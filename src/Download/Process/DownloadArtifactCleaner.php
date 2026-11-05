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

        $dir = \dirname($expectedFile);
        $filename = \pathinfo($expectedFile, PATHINFO_FILENAME);
        if (\is_dir($dir) && $filename !== '') {
            $files = @\scandir($dir);
            if (\is_array($files)) {
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    if (!\str_starts_with($file, $filename)) {
                        continue;
                    }
                    if (!$this->isPartialArtifactFilename($file)) {
                        continue;
                    }
                    $filePath = $dir . DIRECTORY_SEPARATOR . $file;
                    if (\is_file($filePath) && !\in_array($filePath, $candidates, true) && @\unlink($filePath)) {
                        ++$removedCount;
                    }
                }
            }
        }

        return $removedCount;
    }

    private function isPartialArtifactFilename(string $file): bool
    {
        return \str_ends_with($file, '.part')
            || \str_ends_with($file, '.ytdl')
            || \str_ends_with($file, '.temp')
            || \str_contains($file, '.part.')
            || \str_contains($file, '.temp.');
    }
}
