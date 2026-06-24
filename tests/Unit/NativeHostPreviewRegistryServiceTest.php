<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\NativeHost\NativeHostPreviewRegistryService;

final class NativeHostPreviewRegistryServiceTest extends TestCase
{
    public function testRegisterReturnsTokenizedLoopbackPreviewUrlAndResolvesEntry(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_registry_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $filePath = $root . '/preview-video.mp4';
            \file_put_contents($filePath, 'preview');

            $registry = new NativeHostPreviewRegistryService(
                new RuntimeBootstrap($root),
                3600,
                static fn(): DateTimeImmutable => new DateTimeImmutable('2026-05-30T12:00:00+00:00'),
            );

            $preview = $registry->register('job-123', $filePath, 38123);
            $resolved = $registry->resolve('job-123', (string) $preview['token']);

            self::assertTrue($preview['previewReady']);
            self::assertSame('job-123', $preview['jobId']);
            self::assertMatchesRegularExpression(
                '#^http://127\.0\.0\.1:38123/preview/job-123\?token=[a-f0-9]+$#',
                (string) $preview['previewUrl'],
            );
            self::assertSame($filePath, $resolved['path'] ?? null);
            self::assertSame('job-123', $resolved['jobId'] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testResolveReturnsNullForExpiredPreviewAndPrunesIt(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_registry_expired_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        $now = new DateTimeImmutable('2026-05-30T12:00:00+00:00');

        try {
            $filePath = $root . '/preview-video.mp4';
            \file_put_contents($filePath, 'preview');

            $registry = new NativeHostPreviewRegistryService(
                new RuntimeBootstrap($root),
                30,
                static fn() => $now,
            );

            $preview = $registry->register('job-123', $filePath, 38123);
            $expiredRegistry = new NativeHostPreviewRegistryService(
                new RuntimeBootstrap($root),
                30,
                static fn(): DateTimeImmutable => new DateTimeImmutable('2026-05-30T12:01:00+00:00'),
            );

            self::assertNull($expiredRegistry->resolve('job-123', (string) $preview['token']));
            self::assertNull($expiredRegistry->resolve('job-123', (string) $preview['token']));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
