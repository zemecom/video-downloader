<?php

declare(strict_types=1);

namespace YtdPhp\Download\Process;

final readonly class DownloadTemporaryStorage
{
    public function writeTemporaryFile(string $prefix, string $contents): ?string
    {
        $path = \tempnam(\sys_get_temp_dir(), $prefix);
        if ($path === false) {
            return null;
        }

        if (\file_put_contents($path, $contents) === false) {
            @\unlink($path);

            return null;
        }

        return $path;
    }

    public function createTemporaryDirectory(string $prefix): ?string
    {
        $path = \tempnam(\sys_get_temp_dir(), $prefix);
        if ($path === false) {
            return null;
        }

        if (\file_exists($path)) {
            \unlink($path);
        }

        return \mkdir($path, 0777, true) ? $path : null;
    }

    public function removeFileIfExists(string $path): void
    {
        if (\file_exists($path)) {
            \unlink($path);
        }
    }
}
