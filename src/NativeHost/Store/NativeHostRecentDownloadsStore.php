<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Store;

use DateTimeImmutable;
use JsonException;
use PDO;
use PDOException;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Runtime\RuntimeBootstrap;

use const JSON_THROW_ON_ERROR;

final readonly class NativeHostRecentDownloadsStore
{
    private const string TABLE_NAME = 'recent_downloads';
    private const string META_TABLE_NAME = 'recent_downloads_meta';
    private const string LEGACY_MIGRATION_FLAG = 'legacy_json_migrated';

    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function append(string $path, string $url, string $mode): array
    {
        $entry = [
            'id' => 'download-' . \uniqid(),
            'name' => \basename($path),
            'path' => $path,
            'url' => $url,
            'mode' => $mode,
            'createdAt' => new DateTimeImmutable()->format(DATE_ATOM),
        ];

        $database = $this->openDatabase();
        $this->migrateLegacyJsonIfNeeded($database);

        $existingItems = $this->fetchItems($database);
        $filteredItems = \array_filter(
            $existingItems,
            static fn(array $item): bool => ($item['path'] ?? '') !== $path,
        );

        $this->persistItems($database, [
            $entry,
            ...$filteredItems,
        ]);

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $database = $this->openDatabase();
        $this->migrateLegacyJsonIfNeeded($database);

        $storedItems = $this->fetchItems($database);
        $items = $this->filterExistingItems($storedItems);

        if ($items !== $storedItems) {
            $this->persistItems($database, $items);
        }

        return $items;
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
        $database = $this->openDatabase();
        $this->migrateLegacyJsonIfNeeded($database);

        $statement = $database->prepare('DELETE FROM ' . self::TABLE_NAME . ' WHERE id = :id');
        $statement->bindValue(':id', $entryId);
        $statement->execute();
    }

    public function clear(): void
    {
        $database = $this->openDatabase();
        $this->migrateLegacyJsonIfNeeded($database);

        $database->exec('DELETE FROM ' . self::TABLE_NAME);
    }

    private function openDatabase(): PDO
    {
        $path = $this->bootstrap->getNativeHostRecentDownloadsPath();
        new Filesystem()->mkdir(\dirname($path));

        try {
            $database = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $exception) {
            throw new \RuntimeException('Failed to open recent downloads SQLite database.', 0, $exception);
        }

        $this->ensureSchema($database);

        return $database;
    }

    private function ensureSchema(PDO $database): void
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE_NAME . ' (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                path TEXT NOT NULL,
                url TEXT NOT NULL DEFAULT \'\',
                mode TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );
        $database->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::META_TABLE_NAME . ' (
                meta_key TEXT PRIMARY KEY,
                meta_value TEXT NOT NULL
            )',
        );
    }

    private function migrateLegacyJsonIfNeeded(PDO $database): void
    {
        if ($this->isMigrationCompleted($database, self::LEGACY_MIGRATION_FLAG)) {
            return;
        }

        $legacyPath = $this->bootstrap->getLegacyNativeHostRecentDownloadsPath();
        if (!\file_exists($legacyPath)) {
            $this->markMigrationCompleted($database, self::LEGACY_MIGRATION_FLAG);

            return;
        }

        try {
            $decoded = \json_decode((string) \file_get_contents($legacyPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->markMigrationCompleted($database, self::LEGACY_MIGRATION_FLAG);

            return;
        }

        $items = $this->filterExistingItems(\is_array($decoded) ? \array_values(\array_filter($decoded, is_array(...))) : []);
        if ($items !== []) {
            $this->persistItems($database, [
                ...$items,
                ...$this->fetchItems($database),
            ]);
        }

        $this->markMigrationCompleted($database, self::LEGACY_MIGRATION_FLAG);
    }

    private function isMigrationCompleted(PDO $database, string $flag): bool
    {
        $statement = $database->prepare(
            'SELECT meta_value FROM ' . self::META_TABLE_NAME . ' WHERE meta_key = :key LIMIT 1',
        );
        $statement->bindValue(':key', $flag);
        $statement->execute();

        return $statement->fetchColumn() === '1';
    }

    private function markMigrationCompleted(PDO $database, string $flag): void
    {
        $statement = $database->prepare(
            'INSERT INTO ' . self::META_TABLE_NAME . ' (meta_key, meta_value)
             VALUES (:key, :value)
             ON CONFLICT(meta_key) DO UPDATE SET meta_value = excluded.meta_value',
        );
        $statement->bindValue(':key', $flag);
        $statement->bindValue(':value', '1');
        $statement->execute();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItems(PDO $database): array
    {
        $statement = $database->query(
            'SELECT id, name, path, url, mode, created_at
             FROM ' . self::TABLE_NAME . '
             ORDER BY datetime(created_at) DESC, id DESC',
        );
        $rows = $statement !== false ? $statement->fetchAll() : [];

        return \array_map(
            static fn(array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'mode' => (string) ($row['mode'] ?? ''),
                'createdAt' => (string) ($row['created_at'] ?? ''),
            ],
            \is_array($rows) ? $rows : [],
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function persistItems(PDO $database, array $items): void
    {
        $items = $this->normalizeItems($items);

        $database->beginTransaction();

        try {
            $database->exec('DELETE FROM ' . self::TABLE_NAME);

            $statement = $database->prepare(
                'INSERT INTO ' . self::TABLE_NAME . ' (id, name, path, url, mode, created_at)
                 VALUES (:id, :name, :path, :url, :mode, :created_at)',
            );

            foreach ($items as $item) {
                $statement->bindValue(':id', (string) ($item['id'] ?? ''));
                $statement->bindValue(':name', (string) ($item['name'] ?? ''));
                $statement->bindValue(':path', (string) ($item['path'] ?? ''));
                $statement->bindValue(':url', (string) ($item['url'] ?? ''));
                $statement->bindValue(':mode', (string) ($item['mode'] ?? 'video'));
                $statement->bindValue(':created_at', (string) ($item['createdAt'] ?? ''));
                $statement->execute();
            }

            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $id = \is_string($item['id'] ?? null) ? $item['id'] : '';
            $path = \is_string($item['path'] ?? null) ? $item['path'] : '';
            if ($id === '' || $path === '') {
                continue;
            }

            $normalized[$id] = [
                'id' => $id,
                'name' => \is_string($item['name'] ?? null) && $item['name'] !== '' ? $item['name'] : \basename($path),
                'path' => $path,
                'url' => \is_string($item['url'] ?? null) ? $item['url'] : '',
                'mode' => ($item['mode'] ?? null) === 'audio' ? 'audio' : 'video',
                'createdAt' => \is_string($item['createdAt'] ?? null) && $item['createdAt'] !== ''
                    ? $item['createdAt']
                    : new DateTimeImmutable()->format(DATE_ATOM),
            ];
        }

        \usort(
            $normalized,
            static fn(array $left, array $right): int => [(string) ($right['createdAt'] ?? ''), (string) ($right['id'] ?? '')] <=> [(string) ($left['createdAt'] ?? ''), (string) ($left['id'] ?? '')],
        );

        return \array_values($normalized);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function filterExistingItems(array $items): array
    {
        return \array_values(\array_filter(
            $items,
            static function (array $item): bool {
                $path = $item['path'] ?? null;

                return is_string($path) && $path !== '' && \file_exists($path);
            },
        ));
    }
}
