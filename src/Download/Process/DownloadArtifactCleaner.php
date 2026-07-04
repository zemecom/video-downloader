<?php

declare(strict_types=1);

namespace YtdPhp\Download\Process;

final readonly class DownloadArtifactCleaner
{
    public function cleanupFailedDownloadArtifacts(?string $expectedFile): int
    {
        if (!\is_string($expectedFile) || $expectedFile === '') {
            return 0;
        }

        $removedCount = 0;
        $candidates = [
            $expectedFile,
            $expectedFile . '.part',
            $expectedFile . '.ytdl',
        ];

        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && @\unlink($candidate)) {
                ++$removedCount;
            }
        }

        return $removedCount;
    }
}
