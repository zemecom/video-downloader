<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Closure;
use DateTimeImmutable;
use JsonException;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Bootstrap\RuntimeBootstrap;

use function array_filter;
use function array_slice;
use function array_unshift;
use function array_values;
use function basename;
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

final readonly class NativeHostRecentDownloadsStore
{
    private const int MAX_ITEMS = 20;

    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function append(string $path, string $url, string $mode): array
    {
        $entry = [
            'id' => 'download-' . uniqid(),
            'name' => basename($path),
            'path' => $path,
            'url' => $url,
            'mode' => $mode,
            'createdAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $this->withExclusiveLock(function () use ($entry): void {
            $items = $this->readAll();
            array_unshift($items, $entry);
            $this->writeAll(array_slice($items, 0, self::MAX_ITEMS));
        });

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->readAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $entryId): ?array
    {
        foreach ($this->list() as $item) {
            if (($item['id'] ?? null) === $entryId) {
                return $item;
            }
        }

        return null;
    }

    public function remove(string $entryId): void
    {
        $this->withExclusiveLock(function () use ($entryId): void {
            $items = array_values(array_filter(
                $this->readAll(),
                static fn(array $item): bool => ($item['id'] ?? null) !== $entryId,
            ));

            $this->writeAll($items);
        });
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function writeAll(array $items): void
    {
        $path = $this->bootstrap->getNativeHostRecentDownloadsPath();
        (new Filesystem())->mkdir(dirname($path));

        try {
            $encoded = json_encode($items, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
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
     * @return list<array<string, mixed>>
     */
    private function readAll(): array
    {
        $path = $this->bootstrap->getNativeHostRecentDownloadsPath();
        if (!file_exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function withExclusiveLock(Closure $callback): void
    {
        $lockPath = $this->bootstrap->getNativeHostRecentDownloadsPath() . '.lock';
        (new Filesystem())->mkdir(dirname($lockPath));

        $handle = fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            $callback();

            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                $callback();

                return;
            }

            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
