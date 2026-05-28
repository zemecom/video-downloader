<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\NativeHostRecentDownloadsStore;

use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

final class NativeHostRecentDownloadsStoreTest extends TestCase
{
    public function testAppendCreatesNewestFirstListWithMetadata(): void
    {
        $root = sys_get_temp_dir() . '/ytd_recent_downloads_' . uniqid();
        mkdir($root, 0777, true);

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostRecentDownloadsStore(new RuntimeBootstrap($root));
            $first = $store->append('/tmp/video-one.mkv', 'https://example.com/1', 'video');
            $second = $store->append('/tmp/audio-two.opus', 'https://example.com/2', 'audio');
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
        $root = sys_get_temp_dir() . '/ytd_recent_downloads_remove_' . uniqid();
        mkdir($root, 0777, true);

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostRecentDownloadsStore(new RuntimeBootstrap($root));
            $entry = $store->append('/tmp/video-one.mkv', 'https://example.com/1', 'video');

            $store->remove((string) $entry['id']);

            self::assertSame([], $store->list());
            self::assertNull($store->find((string) $entry['id']));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testAppendKeepsOnlyTwentyNewestEntries(): void
    {
        $root = sys_get_temp_dir() . '/ytd_recent_downloads_limit_' . uniqid();
        mkdir($root, 0777, true);

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostRecentDownloadsStore(new RuntimeBootstrap($root));

            for ($index = 1; $index <= 21; ++$index) {
                $store->append('/tmp/video-' . $index . '.mkv', 'https://example.com/' . $index, 'video');
            }

            $items = $store->list();

            self::assertCount(20, $items);
            self::assertSame('video-21.mkv', $items[0]['name']);
            self::assertSame('video-2.mkv', $items[19]['name']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
