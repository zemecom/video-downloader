<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\NativeHostHandlerService;
use YtdPhp\Service\NativeHostJobManagerService;
use YtdPhp\Service\NativeHostJobStateStore;

use function mkdir;
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

    public function testHandleReturnsSpawnFailedWhenManagerThrows(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_handler_spawn_' . uniqid();
        mkdir($root, 0777, true);
        $bootstrap = new RuntimeBootstrap($root);
        $manager = new NativeHostJobManagerService(
            $bootstrap,
            new NativeHostJobStateStore($bootstrap),
            static function (): void {
                throw new RuntimeException('process_start_failed');
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
    }
}
