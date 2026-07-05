<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\NativeHost\Store\NativeHostRecentDownloadsStore;

use const JSON_THROW_ON_ERROR;

final class NativeHostRecentDownloadsStoreTest extends TestCase
{
    public function testAppendCreatesNewestFirstListWithMetadata(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_recent_downloads_' . \uniqid();
        \mkdir($root, 0777, true);

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostRecentDownloadsStore(new RuntimeBootstrap($root));
            $firstPath = $root . '/video-one.mkv';
            $secondPath = $root . '/audio-two.opus';
            \touch($firstPath);
            \touch($secondPath);
            $first = $store->append($firstPath, 'https://example.com/1', 'video');
            $second = $store->append($secondPath, 'https://example.com/2', 'audio');
            $items = $store->list();

            self::assertCount(2, $items);
            self::assertSame($second['id'], $items[0]['id']);
            self::assertSame('audio-two.opus', $items[0]['name']);
            self::assertSame('audio', $items[0]['mode']);
            self::assertSame($first['id'], $items[1]['id']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRemoveDeletesEntryFromStore(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_recent_downloads_remove_' . \uniqid();
        \mkdir($root, 0777, true);

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostRecentDownloadsStore(new RuntimeBootstrap($root));
            $path = $root . '/video-one.mkv';
            \touch($path);
            $entry = $store->append($path, 'https://example.com/1', 'video');

            $store->remove((string) $entry['id']);

            self::assertSame([], $store->list());
            self::assertNull($store->find((string) $entry['id']));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testAppendKeepsAllEntriesWithoutTruncation(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_recent_downloads_limit_' . \uniqid();
        \mkdir($root, 0777, true);

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostRecentDownloadsStore(new RuntimeBootstrap($root));

            for ($index = 1; $index <= 30; ++$index) {
                $path = $root . '/video-' . $index . '.mkv';
                \touch($path);
                $store->append($path, 'https://example.com/' . $index, 'video');
            }

            $items = $store->list();

            self::assertCount(30, $items);
            self::assertSame('video-30.mkv', $items[0]['name']);
            self::assertSame('video-1.mkv', $items[29]['name']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testListPrunesMissingFilesAndPersistsCleanedHistory(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_recent_downloads_prune_' . \uniqid();
        \mkdir($root . '/downloads', 0777, true);

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $bootstrap = new RuntimeBootstrap($root);
            $store = new NativeHostRecentDownloadsStore($bootstrap);
            $existingPath = $root . '/downloads/existing-video.mp4';
            \mkdir(\dirname($bootstrap->getLegacyNativeHostRecentDownloadsPath()), 0777, true);
            \touch($existingPath);

            \file_put_contents(
                $bootstrap->getLegacyNativeHostRecentDownloadsPath(),
                json_encode([
                    [
                        'id' => 'download-missing',
                        'name' => 'missing-video.mp4',
                        'path' => $root . '/downloads/missing-video.mp4',
                        'url' => 'https://example.com/missing',
                        'mode' => 'video',
                        'createdAt' => '2026-06-16T00:00:00+00:00',
                    ],
                    [
                        'id' => 'download-existing',
                        'name' => 'existing-video.mp4',
                        'path' => $existingPath,
                        'url' => 'https://example.com/existing',
                        'mode' => 'video',
                        'createdAt' => '2026-06-16T00:00:01+00:00',
                    ],
                ], JSON_THROW_ON_ERROR),
            );

            $items = $store->list();
            $database = new PDO('sqlite:' . $bootstrap->getNativeHostRecentDownloadsPath());
            $persistedIds = $database->query('SELECT id FROM recent_downloads ORDER BY datetime(created_at) DESC, id DESC')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

            self::assertCount(1, $items);
            self::assertSame('download-existing', $items[0]['id']);

            $store->remove('download-existing');

            self::assertSame(['download-existing'], $persistedIds);
            self::assertSame([], $store->list());
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
