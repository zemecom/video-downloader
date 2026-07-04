<?php

declare(strict_types=1);

namespace YtdPhp\Download\Metadata;

use YtdPhp\Download\DownloadResult;
use YtdPhp\Download\YtDlp\YtDlpCommandBuilder;
use YtdPhp\Download\YtDlp\YtDlpGateway;
use YtdPhp\Download\Process\DownloadTemporaryStorage;
use YtdPhp\Shared\ConsoleLogger;

final readonly class DownloadMetadataService
{
    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private ConsoleLogger $logger,
        private DownloadTemporaryStorage $temporaryStorage,
    ) {}

    public function fetch(string $videoUrl, ?string $proxy, bool $insecure): DownloadMetadataFetchResult
    {
        $this->logger->info('⏳ Получаю метаданные...');

        $builder = new YtDlpCommandBuilder($videoUrl);
        $builder->setProxy($proxy)->setInsecure($insecure);
        $process = $this->ytDlpClient->runCaptured($builder->buildForMetadata());
        if (!$process->isSuccessful()) {
            $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'metadata_failed');
            $this->logger->error('😭 Ой, во время загрузки или получения метаданных произошла ошибка.');
            $this->logger->error('❌ Подробности: ' . $detail);

            return DownloadMetadataFetchResult::failure(new DownloadResult('failed', $detail));
        }

        $tempJsonPath = $this->temporaryStorage->writeTemporaryFile('ytd_', $process->getOutput());
        if ($tempJsonPath === null) {
            return DownloadMetadataFetchResult::failure(new DownloadResult('failed', 'tempfile_failed'));
        }

        $metadata = \json_decode($process->getOutput(), true);
        if (!\is_array($metadata)) {
            $metadata = [];
        }

        return DownloadMetadataFetchResult::success(new DownloadMetadata($tempJsonPath, $metadata));
    }
}
