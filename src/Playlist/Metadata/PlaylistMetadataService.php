<?php

declare(strict_types=1);

namespace YtdPhp\Playlist\Metadata;

use YtdPhp\Playlist\Dto\PlaylistInfo;
use YtdPhp\Download\YtDlp\YtDlpGateway;
use YtdPhp\Download\YtDlp\YtDlpCommandBuilder;
use YtdPhp\Runtime\RuntimeOptions;
use YtdPhp\Shared\ConsoleLogger;

final readonly class PlaylistMetadataService
{
    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private ConsoleLogger $logger,
        private PlaylistPayloadMapper $payloadMapper,
    ) {}

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

        $payload = $this->payloadMapper->decode($process->getOutput());
        if ($payload === null) {
            $this->logger->error('😭 Не удалось прочитать плейлист.');
            $this->logger->error('❌ Подробности: yt-dlp вернул неожиданный JSON для плейлиста.');

            return null;
        }

        return $this->payloadMapper->mapPlaylistInfo($videoUrl, $payload);
    }

    public function probePlaylistPayloadType(string $videoUrl, RuntimeOptions $options): ?string
    {
        $builder = new YtDlpCommandBuilder($videoUrl, true);
        $builder->setProxy($options->currentProxy)->setInsecure($options->insecure);
        $process = $this->ytDlpClient->runCaptured($builder->buildForPlaylistMetadata());
        if (!$process->isSuccessful()) {
            return null;
        }
        $payload = $this->payloadMapper->decode($process->getOutput());
        if ($payload === null) {
            return null;
        }

        $payloadType = \strtolower(\trim((string) ($payload['_type'] ?? '')));

        return $payloadType !== '' ? $payloadType : null;
    }
}
