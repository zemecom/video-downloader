<?php

declare(strict_types=1);

namespace YtdPhp\Download\Process;

use YtdPhp\Shared\ConsoleLogger;

final readonly class DownloadOutputFormatter
{
    public function __construct(
        private ConsoleLogger $logger,
    ) {}

    public function formatSize(int $sizeBytes): string
    {
        if ($sizeBytes === 0) {
            return '0B';
        }

        $units = ['B', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
        $index = (int) \floor(\log((float) $sizeBytes, 1024));
        $power = 1024 ** $index;
        $size = \number_format($sizeBytes / $power, 2, '.', '');
        $size = \preg_replace('/\.00$/', '', (string) $size);

        return $size . $units[$index];
    }

    public function formatElapsedRuntime(float $seconds): string
    {
        if ($seconds < 1.0) {
            return \number_format($seconds, 2, '.', '') . 'с';
        }

        $totalSeconds = (int) \floor($seconds);
        $hours = \intdiv($totalSeconds, 3600);
        $minutes = \intdiv($totalSeconds % 3600, 60);
        $remainingSeconds = $totalSeconds % 60;

        if ($hours > 0) {
            return \sprintf('%dч %02dм %02dс', $hours, $minutes, $remainingSeconds);
        }

        if ($minutes > 0) {
            return \sprintf('%dм %02dс', $minutes, $remainingSeconds);
        }

        return \sprintf('%dс', $remainingSeconds);
    }

    public function logOutputPath(string $expectedFile, ?int $sizeBytes = null): void
    {
        $this->logger->info('📄 Файл: ' . $expectedFile);

        if ($sizeBytes !== null) {
            $this->logger->info('📦 Размер: ' . $this->formatSize($sizeBytes));
        }
    }

    public function logOutputDirectory(string $expectedFile): void
    {
        $this->logger->info('📂 Каталог: ' . \dirname($expectedFile));
    }

    public function logExistingOutputTarget(string $expectedFile): void
    {
        $size = \filesize($expectedFile);
        $this->logOutputPath($expectedFile, $size !== false ? $size : null);
        $this->logOutputDirectory($expectedFile);
    }
}
