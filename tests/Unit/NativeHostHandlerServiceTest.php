<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\NativeHostHandlerService;
use YtdPhp\Service\NativeHostJobManagerService;
use YtdPhp\Service\NativeHostRecentDownloadsStore;
use YtdPhp\Service\NativeHostJobStateStore;

use function mkdir;
use function touch;
use function sys_get_temp_dir;
use function uniqid;

final class NativeHostHandlerServiceTest extends TestCase
{
    public function testHandleAcceptsValidDownloadRequestAndStartsJob(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_handler_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $captured = null;
            $bootstrap = new RuntimeBootstrap($root);
            $manager = new NativeHostJobManagerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                static function (string $jobId, string $url, string $mode, string $logPath) use (&$captured): void {
                    $captured = [$jobId, $url, $mode, $logPath];
                },
                static function (): void {},
            );
            $service = new NativeHostHandlerService($manager);

            $response = $service->handle([
                'action' => 'start_download',
                'url' => 'https://example.com/watch?v=42',
            ]);

            self::assertTrue($response->ok);
            self::assertSame('accepted', $response->code);
            self::assertSame('https://example.com/watch?v=42', $response->url);
            self::assertSame('https://example.com/watch?v=42', $captured[1] ?? null);
            self::assertSame('video', $captured[2] ?? null);
            self::assertIsString($response->details['jobId'] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testHandlePassesAudioModeToJobStarter(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_handler_audio_' . uniqid();
        mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $captured = null;
            $bootstrap = new RuntimeBootstrap($root);
            $manager = new NativeHostJobManagerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                static function (string $jobId, string $url, string $mode, string $logPath) use (&$captured): void {
                    $captured = [$jobId, $url, $mode, $logPath];
                },
                static function (): void {},
            );
            $service = new NativeHostHandlerService($manager);

            $response = $service->handle([
                'action' => 'start_download',
                'url' => 'https://example.com/watch?v=42',
                'mode' => 'audio',
            ]);

            self::assertTrue($response->ok);
            self::assertSame('audio', $captured[2] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testHandleRejectsUnsupportedUrlScheme(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_handler_url_' . uniqid();
        mkdir($root, 0777, true);
        $bootstrap = new RuntimeBootstrap($root);
        $manager = new NativeHostJobManagerService(
            $bootstrap,
            new NativeHostJobStateStore($bootstrap),
            static function (): void {},
            static function (): void {},
        );
        $service = new NativeHostHandlerService($manager);

        $response = $service->handle([
            'action' => 'start_download',
            'url' => 'chrome://extensions',
        ]);

        self::assertFalse($response->ok);
        self::assertSame('unsupported_page', $response->code);
    }

    public function testHandleRejectsUnsupportedActionAsInvalidPayload(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_handler_action_' . uniqid();
        mkdir($root, 0777, true);
        $bootstrap = new RuntimeBootstrap($root);
        $manager = new NativeHostJobManagerService(
            $bootstrap,
            new NativeHostJobStateStore($bootstrap),
            static function (): void {},
            static function (): void {},
        );
        $service = new NativeHostHandlerService($manager);

        $response = $service->handle([
            'action' => 'download_playlist',
            'url' => 'https://example.com/watch?v=42',
        ]);

        self::assertFalse($response->ok);
        self::assertSame('invalid_payload', $response->code);
    }

    public function testHandleReturnsSpawnFailedAndFailedStateWhenWorkerStartFails(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_handler_spawn_' . uniqid();
        mkdir($root, 0777, true);
        $bootstrap = new RuntimeBootstrap($root);
        $store = new NativeHostJobStateStore($bootstrap);
        $manager = new NativeHostJobManagerService(
            $bootstrap,
            $store,
            static function (): void {
                throw new \RuntimeException('process_start_failed');
            },
            static function (): void {},
        );
        $service = new NativeHostHandlerService($manager);

        $response = $service->handle([
            'action' => 'start_download',
            'url' => 'https://example.com/watch?v=42',
        ]);

        self::assertFalse($response->ok);
        self::assertSame('spawn_failed', $response->code);
        self::assertSame('failed', $response->details['status'] ?? null);
        self::assertIsString($response->details['jobId'] ?? null);
        self::assertSame(
            'failed',
            $store->read((string) $response->details['jobId'])['status'] ?? null,
        );
    }

    public function testHandleReturnsUnexpectedErrorWhenNonNativeHostExceptionEscapes(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_handler_unexpected_' . uniqid();
        mkdir($root, 0777, true);
        $bootstrap = new RuntimeBootstrap($root);
        $recentDownloads = new NativeHostRecentDownloadsStore($bootstrap);
        $filePath = $root . '/downloaded-video.mkv';
        touch($filePath);
        $entry = $recentDownloads->append($filePath, 'https://example.com/1', 'video');
        $manager = new NativeHostJobManagerService(
            $bootstrap,
            new NativeHostJobStateStore($bootstrap),
            static function (): void {},
            static function (): void {},
            recentDownloads: $recentDownloads,
            opener: static function (): void {
                throw new \RuntimeException('boom');
            },
            revealer: static function (): void {},
        );
        $service = new NativeHostHandlerService($manager);

        $response = $service->handle([
            'action' => 'open_recent_download',
            'entryId' => (string) $entry['id'],
        ]);

        self::assertFalse($response->ok);
        self::assertSame('unexpected_error', $response->code);
    }
}
