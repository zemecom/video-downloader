<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Log;

use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use YtdPhp\Runtime\RuntimeBootstrap;

final readonly class NativeHostLogService
{
    private const int MAX_LOG_SIZE_BYTES = 5 * 1024 * 1024; // 5MB

    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    public function append(string $message): void
    {
        $path = $this->bootstrap->getNativeHostLogPath();
        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($path));

        $this->rotateIfNeeded($path, $filesystem);

        $filesystem->appendToFile($path, \sprintf("[%s] %s\n", \date('c'), $message));
    }

    public function appendException(Throwable $error): void
    {
        $this->append($error->getMessage() . "\n" . $error);
    }

    private function rotateIfNeeded(string $path, Filesystem $filesystem): void
    {
        if (!\is_file($path)) {
            return;
        }

        \clearstatcache(true, $path);
        $size = \filesize($path);
        if ($size === false || $size < self::MAX_LOG_SIZE_BYTES) {
            return;
        }

        $backupPath = $path . '.1';
        if ($filesystem->exists($backupPath)) {
            $filesystem->remove($backupPath);
        }
        $filesystem->rename($path, $backupPath);
    }
}
