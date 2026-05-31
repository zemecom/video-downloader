<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Closure;
use DateTimeImmutable;
use JsonException;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Bootstrap\RuntimeBootstrap;

use function bin2hex;
use function dirname;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function is_array;
use function is_resource;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rawurlencode;
use function rename;
use function sprintf;
use function unlink;
use function uniqid;

use const LOCK_EX;
use const LOCK_UN;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class NativeHostPreviewRegistryService
{
    /**
     * @var Closure(): DateTimeImmutable
     */
    private Closure $nowProvider;

    public function __construct(
        private RuntimeBootstrap $bootstrap,
        private int $ttlSeconds = 3600,
        ?Closure $nowProvider = null,
    ) {
        $this->nowProvider = $nowProvider ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @return array<string, mixed>
     */
    public function register(string $jobId, string $path, int $port): array
    {
        $token = bin2hex(random_bytes(16));
        $now = $this->now();
        $entry = [
            'jobId' => $jobId,
            'path' => $path,
            'token' => $token,
            'createdAt' => $now->format(DATE_ATOM),
            'expiresAt' => $now->modify(sprintf('+%d seconds', $this->ttlSeconds))->format(DATE_ATOM),
        ];

        $this->withExclusiveLock(function () use ($jobId, $entry): void {
            $entries = $this->pruneExpiredEntries($this->readAll());
            $entries[$jobId] = $entry;
            $this->writeAll($entries);
        });

        return [
            'previewReady' => true,
            'jobId' => $jobId,
            'token' => $token,
            'previewUrl' => $this->buildPreviewUrl($jobId, $token, $port),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $jobId, string $token): ?array
    {
        return $this->withExclusiveLock(function () use ($jobId, $token): ?array {
            $entries = $this->pruneExpiredEntries($this->readAll());
            $entry = $entries[$jobId] ?? null;

            if (!is_array($entry) || ($entry['token'] ?? null) !== $token) {
                $this->writeAll($entries);

                return null;
            }

            $path = $entry['path'] ?? null;
            if (!is_string($path) || $path === '' || !file_exists($path)) {
                unset($entries[$jobId]);
                $this->writeAll($entries);

                return null;
            }

            $this->writeAll($entries);

            return $entry;
        });
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function pruneExpiredEntries(array $entries): array
    {
        $now = $this->now();
        $active = [];

        foreach ($entries as $jobId => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $expiresAt = $entry['expiresAt'] ?? null;
            if (!is_string($expiresAt) || $expiresAt === '') {
                continue;
            }

            try {
                $expiry = new DateTimeImmutable($expiresAt);
            } catch (\Exception) {
                continue;
            }

            if ($expiry <= $now) {
                continue;
            }

            $active[$jobId] = $entry;
        }

        return $active;
    }

    private function buildPreviewUrl(string $jobId, string $token, int $port): string
    {
        return sprintf(
            'http://127.0.0.1:%d/preview/%s?token=%s',
            $port,
            rawurlencode($jobId),
            rawurlencode($token),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readAll(): array
    {
        $path = $this->bootstrap->getNativeHostPreviewRegistryPath();
        if (!file_exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function writeAll(array $entries): void
    {
        $path = $this->bootstrap->getNativeHostPreviewRegistryPath();
        (new Filesystem())->mkdir(dirname($path));

        try {
            $encoded = json_encode($entries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (JsonException) {
            return;
        }

        $tempPath = sprintf('%s.%s.tmp', $path, uniqid());
        if (file_put_contents($tempPath, $encoded) === false) {
            return;
        }

        if (!rename($tempPath, $path) && file_exists($tempPath)) {
            unlink($tempPath);
        }
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withExclusiveLock(Closure $callback): mixed
    {
        $lockPath = $this->bootstrap->getNativeHostPreviewRegistryPath() . '.lock';
        (new Filesystem())->mkdir(dirname($lockPath));

        $handle = fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            return $callback();
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return $callback();
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function now(): DateTimeImmutable
    {
        return ($this->nowProvider)();
    }
}
