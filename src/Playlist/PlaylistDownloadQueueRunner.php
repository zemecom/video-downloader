<?php

declare(strict_types=1);

namespace YtdPhp\Playlist;

use YtdPhp\Download\DownloaderService;
use YtdPhp\Download\DownloadResult;
use YtdPhp\Runtime\RuntimeOptions;
use YtdPhp\Shared\ConsoleLogger;

final readonly class PlaylistDownloadQueueRunner
{
    public function __construct(
        private DownloaderService $downloader,
        private ConsoleLogger $logger,
    ) {}

    /**
     * @param list<PlaylistDownloadWorkItem> $workItems
     */
    public function run(
        PlaylistSelectionSummary $summary,
        RuntimeOptions $options,
        bool $forceOverwrites,
        array $workItems,
    ): bool {
        $queue = $workItems;
        $running = [];
        $hasErrors = false;
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && \count($running) < $options->concurrentDownloads) {
                $item = array_shift($queue);
                if (!$item instanceof PlaylistDownloadWorkItem) {
                    continue;
                }

                $running[] = $this->startDownload($item, $summary, $options, $forceOverwrites);
            }

            foreach ($running as $index => $runningItem) {
                if ($runningItem->process->isRunning()) {
                    continue;
                }

                $result = $this->downloader->finalizeProcessResult($runningItem->process, $runningItem->metadata->expectedPath, false);
                unset($running[$index]);
                $running = \array_values($running);
                $hasErrors = $hasErrors || $result->status === 'failed';
                $this->reportQueueResult($runningItem->position, \count($summary->selectedItems), $runningItem->metadata, $result);
            }

            \usleep(100000);
        }

        return !$hasErrors;
    }

    private function startDownload(
        PlaylistDownloadWorkItem $item,
        PlaylistSelectionSummary $summary,
        RuntimeOptions $options,
        bool $forceOverwrites,
    ): PlaylistRunningDownload {
        $metadata = $item->metadata;
        $this->logger->info(\sprintf('Старт [%d/%d]: %s', $item->position, \count($summary->selectedItems), $metadata->playlistItem->title));
        $process = $this->downloader->createPlaylistDownloadProcess(
            $metadata->infoJsonPath,
            $metadata->expectedPath,
            $options->currentProxy,
            $options->insecure,
            $metadata->resolvedFormatCode,
            $options->outputFormat,
            $forceOverwrites,
            $metadata->playlistItem->url !== '' ? $metadata->playlistItem->url : $summary->playlist->sourceUrl,
            $options->concurrentFragments,
            $options->progressNewline,
            $options->progressDelta,
        );
        $process->start();

        return new PlaylistRunningDownload($item->position, $metadata, $process);
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
}
