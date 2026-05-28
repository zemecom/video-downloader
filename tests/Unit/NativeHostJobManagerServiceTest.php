<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\NativeHostJobManagerService;
use YtdPhp\Service\NativeHostRecentDownloadsStore;
use YtdPhp\Service\NativeHostJobStateStore;

use function mkdir;
use function touch;
use function sys_get_temp_dir;
use function uniqid;

final class NativeHostJobManagerServiceTest extends TestCase
{
    public function testStartDownloadCreatesJobStateAndReturnsAcceptedPayload(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_jobs_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        $spawned = null;

        try {
            $store = new NativeHostJobStateStore(new RuntimeBootstrap($root));
            $manager = new NativeHostJobManagerService(
                new RuntimeBootstrap($root),
                $store,
                static function (string $jobId, string $url, string $mode, string $logPath) use (&$spawned): void {
                    $spawned = [$jobId, $url, $mode, $logPath];
                },
                static function (): void {},
            );

            $response = $manager->startDownload('https://example.com/watch?v=42');
            $payload = $response->toPayload();

            self::assertTrue($payload['ok']);
            self::assertSame('accepted', $payload['code']);
            self::assertIsString($payload['jobId']);
            self::assertSame('starting', $payload['status']);
            self::assertSame('https://example.com/watch?v=42', $payload['url']);

            $saved = $store->read((string) $payload['jobId']);
            self::assertSame('starting', $saved['status']);
            self::assertSame('https://example.com/watch?v=42', $saved['url']);
            self::assertSame('video', $saved['mode']);
            self::assertSame((string) $payload['jobId'], $spawned[0] ?? null);
            self::assertSame('video', $spawned[2] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testStartDownloadStoresAudioModeAndPassesItToWorker(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_jobs_audio_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        $spawned = null;

        try {
            $store = new NativeHostJobStateStore(new RuntimeBootstrap($root));
            $manager = new NativeHostJobManagerService(
                new RuntimeBootstrap($root),
                $store,
                static function (string $jobId, string $url, string $mode, string $logPath) use (&$spawned): void {
                    $spawned = [$jobId, $url, $mode, $logPath];
                },
                static function (): void {},
            );

            $payload = $manager->startDownload('https://example.com/watch?v=42', 'audio')->toPayload();
            $saved = $store->read((string) $payload['jobId']);

            self::assertSame('audio', $saved['mode']);
            self::assertSame('audio', $spawned[2] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testGetJobStatusReturnsStoredStatePayload(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_status_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostJobStateStore(new RuntimeBootstrap($root));
            $store->write('job-123', [
                'jobId' => 'job-123',
                'url' => 'https://example.com/video',
                'status' => 'downloading',
                'progressPercent' => 48.5,
                'progressText' => '[download] 48.5% of 10.00MiB at 2.00MiB/s ETA 00:04',
                'canCancel' => true,
            ]);

            $manager = new NativeHostJobManagerService(
                new RuntimeBootstrap($root),
                $store,
                static function (): void {},
                static function (): void {},
            );

            $payload = $manager->getJobStatus('job-123')->toPayload();

            self::assertTrue($payload['ok']);
            self::assertSame('job_status', $payload['code']);
            self::assertSame('downloading', $payload['status']);
            self::assertSame(48.5, $payload['progressPercent']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testCancelDownloadMarksStateAsCancellingAndSignalsKnownPid(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_cancel_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        $signalled = [];

        try {
            $store = new NativeHostJobStateStore(new RuntimeBootstrap($root));
            $store->write('job-456', [
                'jobId' => 'job-456',
                'url' => 'https://example.com/video',
                'status' => 'downloading',
                'progressPercent' => 12.0,
                'progressText' => '[download] 12.0%',
                'canCancel' => true,
                'downloadPid' => 4242,
            ]);

            $manager = new NativeHostJobManagerService(
                new RuntimeBootstrap($root),
                $store,
                static function (): void {},
                static function (int $pid) use (&$signalled): void {
                    $signalled[] = $pid;
                },
            );

            $payload = $manager->cancelDownload('job-456')->toPayload();
            $saved = $store->read('job-456');

            self::assertTrue($payload['ok']);
            self::assertSame('cancel_requested', $payload['code']);
            self::assertSame('cancelling', $saved['status']);
            self::assertSame([4242], $signalled);
            self::assertTrue($store->cancelRequested('job-456'));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testListRecentDownloadsReturnsStoredEntries(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_recent_list_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $bootstrap = new RuntimeBootstrap($root);
            $recentDownloads = new NativeHostRecentDownloadsStore($bootstrap);
            $recentDownloads->append('/tmp/video-one.mkv', 'https://example.com/1', 'video');

            $manager = new NativeHostJobManagerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                recentDownloads: $recentDownloads,
            );

            $payload = $manager->listRecentDownloads()->toPayload();

            self::assertTrue($payload['ok']);
            self::assertSame('recent_downloads', $payload['code']);
            self::assertCount(1, $payload['items']);
            self::assertSame('video-one.mkv', $payload['items'][0]['name']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testOpenRecentDownloadUsesConfiguredOpener(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_recent_open_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        $openedPaths = [];

        try {
            $bootstrap = new RuntimeBootstrap($root);
            $recentDownloads = new NativeHostRecentDownloadsStore($bootstrap);
            $filePath = $root . '/downloaded-video.mkv';
            touch($filePath);
            $entry = $recentDownloads->append($filePath, 'https://example.com/1', 'video');

            $manager = new NativeHostJobManagerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                recentDownloads: $recentDownloads,
                opener: static function (string $path) use (&$openedPaths): void {
                    $openedPaths[] = $path;
                },
                revealer: static function (): void {},
            );

            $payload = $manager->openRecentDownload((string) $entry['id'])->toPayload();

            self::assertTrue($payload['ok']);
            self::assertSame([$filePath], $openedPaths);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRevealRecentDownloadKeepsMissingFileEntryInHistory(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_recent_reveal_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $bootstrap = new RuntimeBootstrap($root);
            $recentDownloads = new NativeHostRecentDownloadsStore($bootstrap);
            $entry = $recentDownloads->append($root . '/missing-video.mkv', 'https://example.com/1', 'video');

            $manager = new NativeHostJobManagerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                recentDownloads: $recentDownloads,
                opener: static function (): void {},
                revealer: static function (): void {},
            );

            $payload = $manager->revealRecentDownload((string) $entry['id'])->toPayload();

            self::assertFalse($payload['ok']);
            self::assertSame('file_not_found', $payload['code']);
            self::assertCount(1, $recentDownloads->list());
            self::assertSame($entry['id'], $recentDownloads->list()[0]['id']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testOpenRecentDownloadKeepsMissingFileEntryInHistory(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_recent_open_missing_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $bootstrap = new RuntimeBootstrap($root);
            $recentDownloads = new NativeHostRecentDownloadsStore($bootstrap);
            $entry = $recentDownloads->append($root . '/missing-audio.mp3', 'https://example.com/1', 'audio');

            $manager = new NativeHostJobManagerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                recentDownloads: $recentDownloads,
                opener: static function (): void {},
                revealer: static function (): void {},
            );

            $payload = $manager->openRecentDownload((string) $entry['id'])->toPayload();

            self::assertFalse($payload['ok']);
            self::assertSame('file_not_found', $payload['code']);
            self::assertCount(1, $recentDownloads->list());
            self::assertSame($entry['id'], $recentDownloads->list()[0]['id']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
