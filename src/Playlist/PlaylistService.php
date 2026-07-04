<?php

declare(strict_types=1);

namespace YtdPhp\Playlist;

use YtdPhp\Playlist\Dto\PlaylistInfo;
use YtdPhp\Playlist\Dto\PlaylistItem;
use YtdPhp\Playlist\Dto\PlaylistSelectionSummary;
use YtdPhp\Playlist\Dto\PlaylistDownloadWorkItem;
use YtdPhp\Playlist\Dto\SelectedItemMetadata;
use YtdPhp\Playlist\Metadata\PlaylistMetadataService;
use YtdPhp\Playlist\Metadata\PlaylistPayloadMapper;
use YtdPhp\Playlist\Metadata\PlaylistItemPreflightService;
use YtdPhp\Playlist\Metadata\PlaylistSelectionParser;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Download\DownloaderService;
use YtdPhp\Download\YtDlp\YtDlpGateway;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Runtime\RuntimeOptions;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Shared\InputPrompter;

final readonly class PlaylistService
{
    public const string OVERWRITE_SKIP_ALL = 'skip_all';
    public const string OVERWRITE_OVERWRITE_ALL = 'overwrite_all';
    public const string OVERWRITE_CANCEL = 'cancel';
    private PlaylistDownloadQueueRunner $downloadQueueRunner;
    private PlaylistMetadataService $playlistMetadataService;
    private PlaylistItemPreflightService $itemPreflightService;

    public function __construct(
        YtDlpGateway $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
        private DownloaderService $downloader,
        private ConsoleLogger $logger,
        private InputPrompter $prompter,
        private PlaylistSelectionParser $selectionParser = new PlaylistSelectionParser(),
        ?PlaylistDownloadQueueRunner $downloadQueueRunner = null,
        ?PlaylistPayloadMapper $playlistPayloadMapper = null,
        ?PlaylistMetadataService $playlistMetadataService = null,
        ?PlaylistItemPreflightService $itemPreflightService = null,
    ) {
        $this->downloadQueueRunner = $downloadQueueRunner ?? new PlaylistDownloadQueueRunner($this->downloader, $this->logger);
        $payloadMapper = $playlistPayloadMapper ?? new PlaylistPayloadMapper();
        $this->playlistMetadataService = $playlistMetadataService ?? new PlaylistMetadataService(
            $ytDlpClient,
            $this->logger,
            $payloadMapper,
        );
        $this->itemPreflightService = $itemPreflightService ?? new PlaylistItemPreflightService(
            $ytDlpClient,
            $this->bootstrap,
            $this->downloader,
            $payloadMapper,
        );
    }

    public function shouldTreatAsPlaylist(string $videoUrl, RuntimeOptions $options): bool
    {
        if ($this->bootstrap->looksLikePlaylistUrl($videoUrl)) {
            return true;
        }
        if ($this->looksLikeExplicitSingleVideoUrl($videoUrl)) {
            return false;
        }

        return $this->playlistMetadataService->probePlaylistPayloadType($videoUrl, $options) === 'playlist';
    }

    public function fetchAndPreparePlaylist(string $videoUrl, RuntimeOptions $options): ?PlaylistSelectionSummary
    {
        $playlist = $this->fetchPlaylistInfo($videoUrl, $options);
        if (!$playlist instanceof PlaylistInfo) {
            return null;
        }

        if ($playlist->items === []) {
            $this->logger->error('😭 В плейлисте не найдено ни одного ролика или доступ к нему ограничен.');

            return null;
        }

        if ($options->playlistShowSizes) {
            $playlist = $this->enrichPlaylistWithSizes($playlist, $options);
        }

        $selectedItems = $this->promptPlaylistSelection($playlist, $options->playlistShowSizes);
        if ($selectedItems === []) {
            return null;
        }

        $targetDir = $this->buildPlaylistTargetDir($videoUrl, $playlist->title, $playlist->id, $options->downloadDir);

        return $this->collectSelectedItemsMetadata(
            playlist: $playlist,
            selectedItems: $selectedItems,
            options: $options,
            targetDir: $targetDir,
            concurrentDownloads: $options->concurrentDownloads,
        );
    }

    public function fetchPlaylistInfo(string $videoUrl, RuntimeOptions $options): ?PlaylistInfo
    {
        return $this->playlistMetadataService->fetchPlaylistInfo($videoUrl, $options);
    }

    /**
     * @param list<PlaylistItem> $items
     * @return list<int>
     */
    public function parsePlaylistSelection(string $rawValue, array $items): array
    {
        return $this->selectionParser->parse($rawValue, $items);
    }

    /**
     * @return list<PlaylistItem>
     */
    public function promptPlaylistSelection(PlaylistInfo $playlist, bool $showSizes = true): array
    {
        $selectableCount = \count(\array_filter($playlist->items, static fn(PlaylistItem $item): bool => $item->selectable));
        if ($selectableCount === 0) {
            $this->logger->error('😭 В плейлисте нет доступных роликов для загрузки.');

            return [];
        }

        $this->printPlaylistItems($playlist, $showSizes);
        $this->logger->line('');
        $this->logger->info('Введи номера через запятую или диапазоны, например `1,3,5-8`.');
        $this->logger->info('Можно ввести `all`, чтобы выбрать все доступные ролики.');

        while (true) {
            try {
                $selectedIndexes = $this->parsePlaylistSelection(
                    $this->prompter->ask('Что качаем из плейлиста? '),
                    $playlist->items,
                );

                return \array_values(\array_filter(
                    $playlist->items,
                    static fn(PlaylistItem $item): bool => \in_array($item->playlistIndex, $selectedIndexes, true),
                ));
            } catch (\InvalidArgumentException $error) {
                $this->logger->warning('⚠️ ' . $error->getMessage());
                $this->logger->warning('Попробуй ещё раз.');
            }
        }
    }

    public function printPlaylistSummary(PlaylistSelectionSummary $summary): void
    {
        $this->logger->line('');
        $this->logger->info('📚 Плейлист: ' . $summary->playlist->title);
        if ($summary->playlist->sizesLoaded) {
            $this->logger->info('📚 Размер всего плейлиста: ' . $this->downloader->formatSize($summary->playlist->knownTotalSize));
        }
        if ($summary->playlist->sizesLoaded && $summary->playlist->unknownSizeCount > 0) {
            $this->logger->info('❔ Неизвестный размер во всём плейлисте: ' . $summary->playlist->unknownSizeCount);
        }
        $this->logger->info('📁 Папка: ' . $summary->targetDir);
        $this->logger->info(\sprintf('📦 Выбрано: %d из %d', \count($summary->selectedItems), $summary->playlist->totalCount));
        $this->logger->info('💾 Свободно: ' . $this->downloader->formatSize($summary->freeSpaceBytes));
        $this->logger->info('📏 Размер выбранного: ' . $this->downloader->formatSize($summary->knownTotalSize));
        if ($summary->unknownSizeCount > 0) {
            $this->logger->info('❔ Неизвестный размер: ' . $summary->unknownSizeCount);
        }
        if ($summary->preflightErrorCount > 0) {
            $this->logger->info('⚠️ Ошибок предварительной проверки: ' . $summary->preflightErrorCount);
        }
        $this->logger->info('⚙️ Параллельных загрузок: ' . $summary->concurrentDownloads);
    }

    public function printPlaylistDryRun(PlaylistSelectionSummary $summary): void
    {
        $this->logger->line('');
        $this->logger->info('🧪 Режим dry-run: загрузка не будет запущена.');
        foreach ($summary->selectedItems as $item) {
            $title = $item->playlistItem->title;
            if ($item->errorMessage !== null) {
                $this->logger->error('Ошибка preflight: ' . $title . ' (' . $item->errorMessage . ')');
                continue;
            }
            if ($item->exists) {
                $this->logger->info('Уже существует: ' . $title . ' (' . $item->expectedPath . ')');
                continue;
            }
            $this->logger->info('Будет скачано: ' . $title . ' (' . $item->expectedPath . ')');
        }
    }

    public function promptStorageConfirmation(PlaylistSelectionSummary $summary): bool
    {
        if ($summary->knownTotalSize <= $summary->freeSpaceBytes && $summary->unknownSizeCount === 0) {
            $this->logger->info('Подтверди запуск загрузки.');
        } else {
            $this->logger->warning('⚠️ Перед загрузкой стоит проверить свободное место.');
            $this->logger->info('Свободно: ' . $this->downloader->formatSize($summary->freeSpaceBytes));
            $this->logger->info('Известный размер: ' . $this->downloader->formatSize($summary->knownTotalSize));
            if ($summary->unknownSizeCount > 0) {
                $this->logger->info('Неизвестный размер: ' . $summary->unknownSizeCount . ' рол.');
            }
        }

        return \strtolower($this->prompter->ask('Продолжить загрузку? [y/N]: ')) === 'y';
    }

    public function promptOverwritePolicy(PlaylistSelectionSummary $summary): string
    {
        $existingCount = \count(\array_filter($summary->selectedItems, static fn(SelectedItemMetadata $item): bool => $item->exists));
        if ($existingCount === 0) {
            return self::OVERWRITE_OVERWRITE_ALL;
        }

        $this->logger->warning('⚠️ Уже существуют ' . $existingCount . ' файлов(а) из выбранных.');
        $this->logger->info('Выбери политику: `skip` / `overwrite` / `cancel`');
        while (true) {
            $choice = \strtolower($this->prompter->ask('Что делать с существующими файлами? [skip/overwrite/cancel]: '));
            if (\in_array($choice, ['skip', 'skip_all', 's'], true)) {
                return self::OVERWRITE_SKIP_ALL;
            }
            if (\in_array($choice, ['overwrite', 'overwrite_all', 'o'], true)) {
                return self::OVERWRITE_OVERWRITE_ALL;
            }
            if (\in_array($choice, ['cancel', 'c'], true)) {
                return self::OVERWRITE_CANCEL;
            }
            $this->logger->warning('⚠️ Не понял выбор. Повтори ещё раз.');
        }
    }

    public function downloadPlaylist(PlaylistSelectionSummary $summary, RuntimeOptions $options, string $overwritePolicy): bool
    {
        if ($overwritePolicy === self::OVERWRITE_CANCEL) {
            $this->logger->info('⏭️ Загрузка отменена по выбору пользователя.');
            $this->cleanupPlaylistSummary($summary);

            return false;
        }

        new Filesystem()->mkdir($summary->targetDir);

        $workItems = $this->filterWorkItems($summary, $overwritePolicy);
        if ($workItems === []) {
            $this->logger->info('⏭️ Нечего скачивать после применения политики перезаписи.');
            $this->cleanupPlaylistSummary($summary);

            return true;
        }

        $success = $this->downloadQueueRunner->run(
            summary: $summary,
            options: $options,
            forceOverwrites: $overwritePolicy === self::OVERWRITE_OVERWRITE_ALL,
            workItems: $workItems,
        );

        $this->cleanupPlaylistSummary($summary);

        return $success;
    }

    public function cleanupPlaylistSummary(PlaylistSelectionSummary $summary): void
    {
        foreach ($summary->selectedItems as $item) {
            if (\file_exists($item->infoJsonPath)) {
                \unlink($item->infoJsonPath);
            }
        }
    }

    /**
     * @return list<PlaylistDownloadWorkItem>
     */
    private function filterWorkItems(PlaylistSelectionSummary $summary, string $overwritePolicy): array
    {
        $workItems = [];
        foreach ($summary->selectedItems as $position => $item) {
            if ($item->errorMessage !== null) {
                $this->logger->error(\sprintf('Ошибка [%d/%d]: %s (%s)', $position + 1, \count($summary->selectedItems), $item->playlistItem->title, $item->errorMessage));
                continue;
            }
            if ($item->exists && $overwritePolicy === self::OVERWRITE_SKIP_ALL) {
                $this->logger->info(\sprintf('Пропущено [%d/%d]: %s (уже существует)', $position + 1, \count($summary->selectedItems), $item->playlistItem->title));
                continue;
            }

            $workItems[] = new PlaylistDownloadWorkItem($position + 1, $item);
        }

        return $workItems;
    }

    private function looksLikeExplicitSingleVideoUrl(string $videoUrl): bool
    {
        $parts = \parse_url($videoUrl);
        $hostname = \strtolower((string) ($parts['host'] ?? ''));
        $path = \strtolower((string) ($parts['path'] ?? ''));
        $trimmedPath = \trim($path, '/');

        return \str_contains($hostname, 'youtu.be')
            || $path === '/watch'
            || \str_starts_with($path, '/watch/')
            || $path === '/video'
            || \str_starts_with($path, '/video/')
            || (\str_starts_with($trimmedPath, 'video') && !\str_starts_with($trimmedPath, 'videos'))
            || \str_contains($path, '/shorts/');
    }

    private function enrichPlaylistWithSizes(PlaylistInfo $playlist, RuntimeOptions $options): PlaylistInfo
    {
        $updatedItems = [];
        $knownTotalSize = 0;
        $unknownSizeCount = 0;
        foreach ($playlist->items as $item) {
            if (!$item->selectable) {
                $updatedItems[] = $item;
                continue;
            }
            $enrichedItem = $this->itemPreflightService->buildPlaylistItemSize($item, $playlist, $options);
            $updatedItems[] = $enrichedItem;
            $size = $enrichedItem->filesize ?? $enrichedItem->filesizeApprox;
            if ($size !== null) {
                $knownTotalSize += $size;
            } else {
                ++$unknownSizeCount;
            }
        }

        return new PlaylistInfo(
            $playlist->id,
            $playlist->title,
            $playlist->sourceUrl,
            $updatedItems,
            $playlist->totalCount,
            $knownTotalSize,
            $unknownSizeCount,
            true,
        );
    }

    /**
     * @param list<PlaylistItem> $selectedItems
     */
    private function collectSelectedItemsMetadata(
        PlaylistInfo $playlist,
        array $selectedItems,
        RuntimeOptions $options,
        string $targetDir,
        int $concurrentDownloads,
    ): PlaylistSelectionSummary {
        $selectedMetadata = [];
        foreach ($selectedItems as $item) {
            $selectedMetadata[] = $this->itemPreflightService->buildItemMetadata($playlist, $item, $options, $targetDir);
        }

        $knownTotalSize = 0;
        $unknownSizeCount = 0;
        $preflightErrorCount = 0;
        foreach ($selectedMetadata as $item) {
            $size = $item->filesize ?? $item->filesizeApprox;
            if ($size !== null) {
                $knownTotalSize += $size;
            } elseif ($item->errorMessage === null) {
                ++$unknownSizeCount;
            }
            if ($item->errorMessage !== null) {
                ++$preflightErrorCount;
            }
        }

        $freeSpace = \disk_free_space($targetDir);
        if ($freeSpace === false) {
            $freeSpace = 0;
        }

        return new PlaylistSelectionSummary(
            $playlist,
            $targetDir,
            $selectedMetadata,
            $knownTotalSize,
            $unknownSizeCount,
            (int) $freeSpace,
            $concurrentDownloads,
            $preflightErrorCount,
        );
    }

    private function buildPlaylistTargetDir(string $sourceUrl, string $playlistTitle, string $playlistId, ?string $downloadDir = null): string
    {
        $baseDir = $this->bootstrap->getDownloadBasePath($sourceUrl, $downloadDir);
        $rawName = \trim($playlistTitle) !== '' ? \trim($playlistTitle) : ($playlistId !== '' ? 'playlist_' . $playlistId : 'playlist');
        $safeName = $this->bootstrap->sanitizePathComponent($rawName, 'playlist_' . ($playlistId !== '' ? $playlistId : 'items'));

        return $baseDir . DIRECTORY_SEPARATOR . $safeName;
    }

    private function printPlaylistItems(PlaylistInfo $playlist, bool $showSizes): void
    {
        if ($showSizes && $playlist->sizesLoaded) {
            $this->logger->info('📚 Размер плейлиста: ' . $this->downloader->formatSize($playlist->knownTotalSize));
            if ($playlist->unknownSizeCount > 0) {
                $this->logger->info('❔ Размер не удалось определить для ' . $playlist->unknownSizeCount . ' рол.');
            }
        }
        $this->logger->info('📋 Доступные ролики:');
        foreach ($playlist->items as $item) {
            $mark = $item->selectable ? '✅' : '⛔';
            $statusText = ($item->status !== '' && !\in_array($item->status, ['available', 'public', 'unlisted'], true))
                ? ' [' . $item->status . ']'
                : '';
            $sizeText = $showSizes ? ' (' . $this->playlistItemSizeLabel($item) . ')' : '';
            $this->logger->info(\sprintf('%3d. %s %s%s%s', $item->playlistIndex, $mark, $item->title, $statusText, $sizeText));
        }
    }

    private function playlistItemSizeLabel(PlaylistItem $item): string
    {
        $size = $item->filesize ?? $item->filesizeApprox;
        if ($size === null) {
            return '?';
        }
        if ($item->filesize === null && $item->filesizeApprox !== null) {
            return '~' . $this->downloader->formatSize($size);
        }

        return $this->downloader->formatSize($size);
    }

}
