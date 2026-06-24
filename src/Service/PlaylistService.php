<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Dto\DownloadResult;
use YtdPhp\Dto\PlaylistInfo;
use YtdPhp\Dto\PlaylistItem;
use YtdPhp\Dto\PlaylistSelectionSummary;
use YtdPhp\Dto\RuntimeOptions;
use YtdPhp\Dto\SelectedItemMetadata;

final readonly class PlaylistService
{
    public const string OVERWRITE_SKIP_ALL = 'skip_all';
    public const string OVERWRITE_OVERWRITE_ALL = 'overwrite_all';
    public const string OVERWRITE_CANCEL = 'cancel';

    public function __construct(
        private YtDlpClient $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
        private DownloaderService $downloader,
        private ConsoleLogger $logger,
        private InputPrompter $prompter,
    ) {}

    public function shouldTreatAsPlaylist(string $videoUrl, RuntimeOptions $options): bool
    {
        if ($this->bootstrap->looksLikePlaylistUrl($videoUrl)) {
            return true;
        }
        if ($this->looksLikeExplicitSingleVideoUrl($videoUrl)) {
            return false;
        }

        return $this->probePlaylistPayloadType($videoUrl, $options) === 'playlist';
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
            $playlist,
            $selectedItems,
            $options,
            $targetDir,
            $options->concurrentDownloads,
        );
    }

    public function fetchPlaylistInfo(string $videoUrl, RuntimeOptions $options): ?PlaylistInfo
    {
        $builder = new YtDlpCommandBuilder($videoUrl, true);
        $builder->setProxy($options->currentProxy)->setInsecure($options->insecure);
        $this->logger->info('⏳ Получаю метаданные плейлиста...');
        $process = $this->ytDlpClient->runCaptured($builder->buildForPlaylistMetadata());
        if (!$process->isSuccessful()) {
            $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'playlist_metadata_failed');
            $this->logger->error('😭 Не удалось прочитать плейлист.');
            $this->logger->error('❌ Подробности: ' . $detail);

            return null;
        }

        $payload = $this->decodePlaylistPayload($process->getOutput());
        if ($payload === null) {
            $this->logger->error('😭 Не удалось прочитать плейлист.');
            $this->logger->error('❌ Подробности: yt-dlp вернул неожиданный JSON для плейлиста.');

            return null;
        }

        $playlistId = \trim((string) ($payload['id'] ?? ''));
        $playlistTitle = \trim((string) ($payload['title'] ?? $payload['playlist_title'] ?? $playlistId ?: 'playlist'));
        $entries = $payload['entries'] ?? [];
        if (!\is_array($entries)) {
            $entries = [];
        }

        $items = [];
        foreach ($entries as $index => $entry) {
            $items[] = $this->normalizePlaylistEntry(\is_array($entry) ? $entry : null, $index + 1);
        }

        $totalCount = $this->coerceInt($payload['playlist_count'] ?? null) ?? \count($items);
        if ($totalCount === 0 && $items !== []) {
            $totalCount = \count($items);
        }

        return new PlaylistInfo(
            $playlistId !== '' ? $playlistId : 'playlist',
            $playlistTitle,
            $videoUrl,
            $items,
            $totalCount,
        );
    }

    /**
     * @param list<PlaylistItem> $items
     * @return list<int>
     */
    public function parsePlaylistSelection(string $rawValue, array $items): array
    {
        $cleaned = \strtolower(\trim($rawValue));
        $selectableIndexes = [];
        $maxIndex = 0;
        foreach ($items as $item) {
            $maxIndex = \max($maxIndex, $item->playlistIndex);
            if ($item->selectable) {
                $selectableIndexes[] = $item->playlistIndex;
            }
        }

        if ($cleaned === '') {
            throw new \InvalidArgumentException('Пустой выбор.');
        }
        if ($cleaned === 'all') {
            sort($selectableIndexes);

            return $selectableIndexes;
        }

        $indexes = [];
        $tokens = \array_filter(\array_map('trim', explode(',', $cleaned)), static fn(string $value): bool => $value !== '');
        if ($tokens === []) {
            throw new \InvalidArgumentException('Пустой выбор.');
        }

        foreach ($tokens as $token) {
            if (\str_contains($token, '-')) {
                $parts = explode('-', $token);
                if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    throw new \InvalidArgumentException('Поддерживаются только диапазоны вида 5-8.');
                }
                $start = (int) $parts[0];
                $end = (int) $parts[1];
                if ($start > $end) {
                    throw new \InvalidArgumentException('Начало диапазона не может быть больше конца.');
                }
                for ($number = $start; $number <= $end; ++$number) {
                    $this->validateSelectedNumber($number, $maxIndex, $selectableIndexes);
                    $indexes[$number] = true;
                }
                continue;
            }

            if (!ctype_digit($token)) {
                throw new \InvalidArgumentException('Непонятный выбор: ' . $token);
            }
            $number = (int) $token;
            $this->validateSelectedNumber($number, $maxIndex, $selectableIndexes);
            $indexes[$number] = true;
        }

        if ($indexes === []) {
            throw new \InvalidArgumentException('Не выбрано ни одного ролика.');
        }

        $result = \array_map('intval', array_keys($indexes));
        sort($result);

        return $result;
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

        (new Filesystem())->mkdir($summary->targetDir);

        $workItems = $this->filterWorkItems($summary, $overwritePolicy);
        if ($workItems === []) {
            $this->logger->info('⏭️ Нечего скачивать после применения политики перезаписи.');
            $this->cleanupPlaylistSummary($summary);

            return true;
        }

        $queue = $workItems;
        $running = [];
        $hasErrors = false;
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && \count($running) < $options->concurrentDownloads) {
                $item = array_shift($queue);
                if (!\is_array($item)) {
                    continue;
                }
                $position = $item['position'];
                /** @var SelectedItemMetadata $metadata */
                $metadata = $item['item'];
                $this->logger->info(\sprintf('Старт [%d/%d]: %s', $position, \count($summary->selectedItems), $metadata->playlistItem->title));
                $process = $this->downloader->createPlaylistDownloadProcess(
                    $metadata->infoJsonPath,
                    $metadata->expectedPath,
                    $options->currentProxy,
                    $options->insecure,
                    $metadata->resolvedFormatCode,
                    $options->outputFormat,
                    $overwritePolicy === self::OVERWRITE_OVERWRITE_ALL,
                    $metadata->playlistItem->url !== '' ? $metadata->playlistItem->url : $summary->playlist->sourceUrl,
                    $options->concurrentFragments,
                    $options->progressNewline,
                    $options->progressDelta,
                );
                $process->start();
                $running[] = ['position' => $position, 'item' => $metadata, 'process' => $process];
            }

            foreach ($running as $index => $runningItem) {
                /** @var Process $process */
                $process = $runningItem['process'];
                if ($process->isRunning()) {
                    continue;
                }
                /** @var SelectedItemMetadata $metadata */
                $metadata = $runningItem['item'];
                $position = $runningItem['position'];
                $result = $this->downloader->finalizeProcessResult($process, $metadata->expectedPath, false);
                unset($running[$index]);
                $running = \array_values($running);
                $hasErrors = $hasErrors || $result->status === 'failed';
                $this->reportQueueResult($position, \count($summary->selectedItems), $metadata, $result);
            }

            \usleep(100000);
        }

        $this->cleanupPlaylistSummary($summary);

        return !$hasErrors;
    }

    public function cleanupPlaylistSummary(PlaylistSelectionSummary $summary): void
    {
        foreach ($summary->selectedItems as $item) {
            if (\file_exists($item->infoJsonPath)) {
                \unlink($item->infoJsonPath);
            }
        }
    }

    private function reportQueueResult(int $position, int $total, SelectedItemMetadata $item, DownloadResult $result): void
    {
        $title = $item->playlistItem->title;
        if ($result->status === 'completed') {
            $this->logger->info(\sprintf('Готово [%d/%d]: %s (%s)', $position, $total, $title, $item->expectedPath));

            return;
        }
        if ($result->status === 'skipped') {
            $this->logger->info(\sprintf('Пропущено [%d/%d]: %s (%s)', $position, $total, $title, $result->detail ?? 'skipped'));

            return;
        }

        $this->logger->error(\sprintf('Ошибка [%d/%d]: %s (%s)', $position, $total, $title, $result->detail ?? 'download_failed'));
    }

    /**
     * @return list<array{position:int,item:SelectedItemMetadata}>
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

            $workItems[] = [
                'position' => $position + 1,
                'item' => $item,
            ];
        }

        return $workItems;
    }

    private function probePlaylistPayloadType(string $videoUrl, RuntimeOptions $options): ?string
    {
        $builder = new YtDlpCommandBuilder($videoUrl, true);
        $builder->setProxy($options->currentProxy)->setInsecure($options->insecure);
        $process = $this->ytDlpClient->runCaptured($builder->buildForPlaylistMetadata());
        if (!$process->isSuccessful()) {
            return null;
        }
        $payload = $this->decodePlaylistPayload($process->getOutput());
        if ($payload === null) {
            return null;
        }

        $payloadType = \strtolower(\trim((string) ($payload['_type'] ?? '')));

        return $payloadType !== '' ? $payloadType : null;
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

    /**
     * @return array<mixed>|null
     */
    private function decodePlaylistPayload(string $output): ?array
    {
        $payload = \json_decode(\trim($output), true);
        if (\is_array($payload)) {
            if (array_is_list($payload) && \count($payload) === 1 && \is_array($payload[0])) {
                return $payload[0];
            }

            return $payload;
        }

        return null;
    }

    private function normalizePlaylistEntry(?array $entry, int $index): PlaylistItem
    {
        [$status, $selectable] = $this->detectItemStatus($entry);
        $title = '';
        $url = '';
        if (\is_array($entry)) {
            $title = \trim((string) ($entry['title'] ?? $entry['fulltitle'] ?? $entry['id'] ?? ''));
            $url = \trim((string) ($entry['webpage_url'] ?? $entry['url'] ?? ''));
        }
        if ($title === '') {
            $title = 'Видео ' . $index;
        }

        return new PlaylistItem($index, $title, $url, $status, $selectable);
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function detectItemStatus(?array $entry): array
    {
        if (!\is_array($entry)) {
            return ['unavailable', false];
        }

        $availability = \strtolower(\trim((string) ($entry['availability'] ?? '')));
        $title = \strtolower(\trim((string) ($entry['title'] ?? '')));
        if (\str_contains($availability, 'private') || \str_contains($title, 'private')) {
            return ['private', false];
        }
        if (\str_contains($availability, 'deleted') || \str_contains($title, 'deleted')) {
            return ['deleted', false];
        }
        if (\str_contains($availability, 'unavailable') || \str_contains($title, 'not available')) {
            return ['unavailable', false];
        }
        if ($availability !== '') {
            return [$availability, true];
        }

        return ['available', true];
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
            $enrichedItem = $this->buildPlaylistItemSize($item, $playlist, $options);
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

    private function buildPlaylistItemSize(PlaylistItem $item, PlaylistInfo $playlist, RuntimeOptions $options): PlaylistItem
    {
        $process = $this->probeItemProcess($item, $playlist, $options);
        if (!$process->isSuccessful()) {
            return $item;
        }
        $metadata = $this->decodePlaylistPayload($process->getOutput());
        if ($metadata === null) {
            return $item;
        }

        [$filesize, $filesizeApprox] = $this->estimateItemSize($metadata);

        return new PlaylistItem(
            $item->playlistIndex,
            $item->title,
            $item->url,
            $item->status,
            $item->selectable,
            $filesize,
            $filesizeApprox,
            $filesize !== null || $filesizeApprox !== null,
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
    ): ?PlaylistSelectionSummary {
        $selectedMetadata = [];
        foreach ($selectedItems as $item) {
            $selectedMetadata[] = $this->buildItemMetadata($playlist, $item, $options, $targetDir);
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

    private function buildItemMetadata(
        PlaylistInfo $playlist,
        PlaylistItem $item,
        RuntimeOptions $options,
        string $targetDir,
    ): SelectedItemMetadata {
        $requestedFormatCode = $this->requestedFormatCode($options);
        $usedDirectItemProbe = $this->canProbeItemDirectly($item);
        $process = $this->probeItemProcess($item, $playlist, $options);
        if (!$process->isSuccessful()) {
            $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'playlist_item_metadata_failed');

            return $this->failedItemMetadata($item, $detail);
        }

        $metadata = $this->decodePlaylistPayload($process->getOutput());
        if ($metadata === null) {
            return $this->failedItemMetadata($item, 'playlist_item_metadata_failed');
        }

        $metadata['playlist_index'] ??= $item->playlistIndex;
        $sourceUrl = $item->url !== '' ? $item->url : $playlist->sourceUrl;
        $resolvedFormatCode = $this->downloader->resolveRequestedFormatCode(
            $requestedFormatCode,
            $metadata,
            $sourceUrl,
            $options->outputFormat,
        );
        $tempJsonPath = $this->writePlaylistItemMetadataJson($metadata);
        if ($tempJsonPath === null) {
            return $this->failedItemMetadata($item, 'playlist_item_tempfile_failed');
        }

        $expectedPath = $resolvedFormatCode === 'bestaudio'
            ? ($this->ytDlpClient->getExpectedFilename(
                null,
                $resolvedFormatCode,
                $this->playlistOutputTemplate($targetDir),
                $options->currentProxy,
                $options->insecure,
                $tempJsonPath,
                $options->outputFormat,
            ) ?: $this->fallbackExpectedPath(
                $targetDir,
                $metadata,
                $item->playlistIndex,
                $options->outputFormat,
                $resolvedFormatCode,
            ))
            : ($usedDirectItemProbe
                ? $this->fallbackExpectedPath(
                    $targetDir,
                    $metadata,
                    $item->playlistIndex,
                    $options->outputFormat,
                    $resolvedFormatCode,
                )
                : ($this->ytDlpClient->getExpectedFilename(
                    null,
                    $resolvedFormatCode,
                    $this->playlistOutputTemplate($targetDir),
                    $options->currentProxy,
                    $options->insecure,
                    $tempJsonPath,
                    $options->outputFormat,
                ) ?: $this->fallbackExpectedPath(
                    $targetDir,
                    $metadata,
                    $item->playlistIndex,
                    $options->outputFormat,
                    $resolvedFormatCode,
                )));
        $expectedPath = $this->bootstrap->sanitizeOutputFilename($expectedPath);

        [$filesize, $filesizeApprox] = $this->estimateItemSize($metadata);

        return new SelectedItemMetadata(
            $item,
            $tempJsonPath,
            $expectedPath,
            $resolvedFormatCode,
            \file_exists($expectedPath),
            $filesize,
            $filesizeApprox,
            $filesize !== null || $filesizeApprox !== null,
        );
    }

    /**
     * @param array<mixed> $metadata
     */
    private function writePlaylistItemMetadataJson(array $metadata): ?string
    {
        $tempJson = \tempnam(\sys_get_temp_dir(), 'ytd_playlist_');
        if ($tempJson === false) {
            return null;
        }

        $tempJsonPath = $tempJson . '.json';
        @\unlink($tempJson);

        $encoded = \json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!\is_string($encoded) || \file_put_contents($tempJsonPath, $encoded) === false) {
            if (\file_exists($tempJsonPath)) {
                @\unlink($tempJsonPath);
            }

            return null;
        }

        return $tempJsonPath;
    }

    private function failedItemMetadata(PlaylistItem $item, string $errorMessage): SelectedItemMetadata
    {
        return new SelectedItemMetadata($item, '', '', 'best', false, null, null, false, $errorMessage);
    }

    private function probeItemProcess(PlaylistItem $item, PlaylistInfo $playlist, RuntimeOptions $options): Process
    {
        if ($this->canProbeItemDirectly($item)) {
            $builder = new YtDlpCommandBuilder($item->url);
            $builder->setProxy($options->currentProxy)->setInsecure($options->insecure);

            return $this->ytDlpClient->runCaptured($builder->buildForMetadata());
        }

        $builder = new YtDlpCommandBuilder($playlist->sourceUrl, true);
        $builder->setProxy($options->currentProxy)->setInsecure($options->insecure);

        return $this->ytDlpClient->runCaptured($builder->buildForPlaylistItemMetadata($item->playlistIndex));
    }

    private function canProbeItemDirectly(PlaylistItem $item): bool
    {
        return \str_starts_with($item->url, 'http://') || \str_starts_with($item->url, 'https://');
    }

    private function buildPlaylistTargetDir(string $sourceUrl, string $playlistTitle, string $playlistId, ?string $downloadDir = null): string
    {
        $baseDir = $this->bootstrap->getDownloadBasePath($sourceUrl, $downloadDir);
        $rawName = \trim($playlistTitle) !== '' ? \trim($playlistTitle) : ($playlistId !== '' ? 'playlist_' . $playlistId : 'playlist');
        $safeName = $this->bootstrap->sanitizePathComponent($rawName, 'playlist_' . ($playlistId !== '' ? $playlistId : 'items'));

        return $baseDir . DIRECTORY_SEPARATOR . $safeName;
    }

    private function playlistOutputTemplate(string $targetDir): string
    {
        return $targetDir . '/%(playlist_index)03d - %(title)s [%(id)s].%(ext)s';
    }

    /**
     * @param array<mixed> $metadata
     * @return array{0:?int,1:?int}
     */
    private function estimateItemSize(array $metadata): array
    {
        $filesize = $this->coerceInt($metadata['filesize'] ?? null);
        if ($filesize !== null) {
            return [$filesize, null];
        }

        $filesizeApprox = $this->coerceInt($metadata['filesize_approx'] ?? null);
        if ($filesizeApprox !== null) {
            return [$filesizeApprox, $filesizeApprox];
        }

        $requestedFormats = $metadata['requested_formats'] ?? null;
        if (\is_array($requestedFormats) && $requestedFormats !== []) {
            $sizes = [];
            foreach ($requestedFormats as $format) {
                if (!\is_array($format)) {
                    return [null, null];
                }
                $formatSize = $this->coerceInt($format['filesize'] ?? null) ?? $this->coerceInt($format['filesize_approx'] ?? null);
                if ($formatSize === null) {
                    return [null, null];
                }
                $sizes[] = $formatSize;
            }

            return [array_sum($sizes), null];
        }

        return [null, null];
    }

    private function coerceInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<mixed> $metadata
     */
    private function fallbackExpectedPath(
        string $targetDir,
        array $metadata,
        int $index,
        string $outputFormat,
        string $formatCode = 'best',
    ): string {
        $title = \trim((string) ($metadata['title'] ?? $metadata['fulltitle'] ?? 'video_' . $index));
        $safeTitle = $this->bootstrap->sanitizePathComponent($title, 'video_' . $index);
        $videoId = \trim((string) ($metadata['id'] ?? ''));
        $safeVideoId = $videoId !== ''
            ? $this->bootstrap->sanitizePathComponent($videoId, 'item_' . $index)
            : 'item_' . $index;
        $defaultExtension = $formatCode === 'bestaudio' ? 'opus' : $outputFormat;
        $ext = \trim((string) ($metadata['ext'] ?? $defaultExtension)) ?: $defaultExtension;

        return \sprintf('%s/%03d - %s [%s].%s', $targetDir, $index, $safeTitle, $safeVideoId, $ext);
    }

    private function requestedFormatCode(RuntimeOptions $options): string
    {
        return $options->audioOnly ? 'bestaudio' : $options->qualityPreset;
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

    /**
     * @param list<int> $selectableIndexes
     */
    private function validateSelectedNumber(int $number, int $maxIndex, array $selectableIndexes): void
    {
        if ($number < 1 || $number > $maxIndex) {
            throw new \InvalidArgumentException('Номер ' . $number . ' вне диапазона.');
        }
        if (!\in_array($number, $selectableIndexes, true)) {
            throw new \InvalidArgumentException('Ролик ' . $number . ' нельзя выбрать.');
        }
    }
}
