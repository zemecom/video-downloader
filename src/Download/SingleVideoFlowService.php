<?php

declare(strict_types=1);

namespace YtdPhp\Download;

use YtdPhp\Download\YtDlp\YtDlpGateway;
use Symfony\Component\Console\Command\Command;
use YtdPhp\Runtime\RuntimeOptions;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Shared\InputPrompter;

final readonly class SingleVideoFlowService
{
    /** @var list<string> */
    private const array SUCCESSFUL_DOWNLOAD_STATUSES = ['completed', 'skipped', 'cancelled'];

    public function __construct(
        private ConsoleLogger $logger,
        private InputPrompter $prompter,
        private YtDlpGateway $ytDlpClient,
        private DownloaderService $downloaderService,
    ) {}

    public function handle(string $videoUrl, RuntimeOptions $options): int
    {
        $downloadOptions = DownloadOptions::fromRuntimeOptions($options);

        if ($options->fastMode) {
            $this->logger->info('⚡️ Быстрый режим: скачиваю видео и аудио параллельно...');
            $result = $this->downloaderService->downloadVideoFast(
                videoUrl: $videoUrl,
                qualityPreset: $options->qualityPreset,
                options: $downloadOptions,
            );

            return $this->isSuccessfulDownloadStatus($result->status)
                ? Command::SUCCESS
                : Command::FAILURE;
        }

        $formatCode = $options->manualMode
            ? $this->chooseManualFormat($videoUrl, $options)
            : $this->defaultFormatCode($options, true);

        if ($formatCode === null) {
            return Command::FAILURE;
        }

        $result = $this->downloaderService->downloadVideo(
            videoUrl: $videoUrl,
            formatCode: $formatCode,
            options: $downloadOptions,
        );

        return $this->isSuccessfulDownloadStatus($result->status)
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function chooseManualFormat(string $videoUrl, RuntimeOptions $options): ?string
    {
        if (!$this->ytDlpClient->listFormats($videoUrl, $options->currentProxy, $options->insecure)) {
            return null;
        }

        $defaultFormatCode = $this->defaultFormatCode($options, false);
        $choice = \trim($this->prompter->ask(
            \sprintf(
                "Введи код формата для загрузки (или нажми Enter, чтобы скачать '%s'): ",
                $defaultFormatCode,
            ),
        ));
        $formatCode = $choice !== '' ? $choice : $defaultFormatCode;
        $this->logger->info("Выбран формат: '" . $formatCode . "'");

        return $formatCode;
    }

    private function defaultFormatCode(RuntimeOptions $options, bool $emitLog): string
    {
        if ($emitLog) {
            $this->logger->info(
                $options->audioOnly
                    ? '⚡️ Автоматический режим: скачиваю лучшее аудио...'
                    : '⚡️ Автоматический режим: скачиваю качество ' . $this->qualityPresetLabel($options->qualityPreset) . '...',
            );
        }

        return $options->audioOnly ? 'bestaudio' : $options->qualityPreset;
    }

    private function qualityPresetLabel(string $qualityPreset): string
    {
        return match ($qualityPreset) {
            'fhd' => 'FHD (1080p)',
            'medium' => 'medium (720p)',
            'low' => 'low (480p)',
            default => 'best',
        };
    }

    private function isSuccessfulDownloadStatus(string $status): bool
    {
        return \in_array($status, self::SUCCESSFUL_DOWNLOAD_STATUSES, true);
    }
}
