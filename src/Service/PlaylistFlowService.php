<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Symfony\Component\Console\Command\Command;
use YtdPhp\Dto\RuntimeOptions;

final readonly class PlaylistFlowService
{
    public function __construct(
        private ConsoleLogger $logger,
        private PlaylistService $playlistService,
    ) {}

    public function handle(string $videoUrl, RuntimeOptions $options): int
    {
        if ($options->manualMode) {
            $this->logger->error('Ручной режим пока не поддерживается для плейлистов.');

            return Command::FAILURE;
        }

        $summary = $this->playlistService->fetchAndPreparePlaylist($videoUrl, $options);
        if ($summary === null) {
            return Command::FAILURE;
        }

        $this->playlistService->printPlaylistSummary($summary);
        if ($options->dryRun) {
            $this->playlistService->printPlaylistDryRun($summary);
            $this->playlistService->cleanupPlaylistSummary($summary);

            return $summary->preflightErrorCount === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$this->playlistService->promptStorageConfirmation($summary)) {
            $this->playlistService->cleanupPlaylistSummary($summary);
            $this->logger->info('⏭️ Загрузка отменена.');

            return Command::FAILURE;
        }

        $overwritePolicy = $this->playlistService->promptOverwritePolicy($summary);
        if ($overwritePolicy === PlaylistService::OVERWRITE_CANCEL) {
            $this->playlistService->cleanupPlaylistSummary($summary);
            $this->logger->info('⏭️ Загрузка отменена.');

            return Command::FAILURE;
        }

        return $this->playlistService->downloadPlaylist($summary, $options, $overwritePolicy)
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
