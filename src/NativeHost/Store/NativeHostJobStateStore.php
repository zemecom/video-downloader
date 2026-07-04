<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Store;

use Closure;
use JsonException;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Runtime\RuntimeBootstrap;

use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class NativeHostJobStateStore
{
    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    /**
     * @param array<string, mixed> $state
     */
    public function write(string $jobId, array $state): void
    {
        $path = $this->statePath($jobId);
        $directory = \dirname($path);
        (new Filesystem())->mkdir($directory);

        try {
            $encoded = \json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (JsonException) {
            return;
        }

        $this->withStateLock($jobId, LOCK_EX, function () use ($path, $encoded): void {
            $tempPath = \sprintf('%s.%s.tmp', $path, \uniqid());
            if (\file_put_contents($tempPath, $encoded) === false) {
                return;
            }

            if (!\rename($tempPath, $path) && \file_exists($tempPath)) {
                \unlink($tempPath);
            }
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $jobId): ?array
    {
        $path = $this->statePath($jobId);

        return $this->withStateLock($jobId, LOCK_SH, function () use ($path): ?array {
            if (!\file_exists($path)) {
                return null;
            }

            try {
                $decoded = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }

            return \is_array($decoded) ? $decoded : null;
        });
    }

    public function requestCancel(string $jobId): void
    {
        (new Filesystem())->mkdir($this->bootstrap->getNativeHostJobsDirectoryPath());
        \file_put_contents($this->cancelPath($jobId), 'cancel');
    }

    public function cancelRequested(string $jobId): bool
    {
        return \file_exists($this->cancelPath($jobId));
    }

    public function clearCancelRequest(string $jobId): void
    {
        (new Filesystem())->remove($this->cancelPath($jobId));
    }

    public function statePath(string $jobId): string
    {
        return $this->bootstrap->getNativeHostJobsDirectoryPath() . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    public function cancelPath(string $jobId): string
    {
        return $this->bootstrap->getNativeHostJobsDirectoryPath() . DIRECTORY_SEPARATOR . $jobId . '.cancel';
    }

    private function lockPath(string $jobId): string
    {
        return $this->bootstrap->getNativeHostJobsDirectoryPath() . DIRECTORY_SEPARATOR . $jobId . '.lock';
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withStateLock(string $jobId, int $operation, Closure $callback): mixed
    {
        $lockPath = $this->lockPath($jobId);
        (new Filesystem())->mkdir(\dirname($lockPath));

        $handle = \fopen($lockPath, 'c+');
        if (!\is_resource($handle)) {
            return $callback();
        }

        try {
            if (!\flock($handle, $operation)) {
                return $callback();
            }

            return $callback();
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }
    }
}
