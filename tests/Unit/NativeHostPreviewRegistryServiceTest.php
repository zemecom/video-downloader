<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\NativeHost\Preview\NativeHostPreviewRegistryService;
use YtdPhp\Runtime\RuntimeBootstrap;

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
            $bootstrap = new RuntimeBootstrap($root);

            $registry = new NativeHostPreviewRegistryService($bootstrap);

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

    public function testResolveReturnsLegacyPreviewEntryRegardlessOfCreatedAt(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_registry_legacy_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $filePath = $root . '/preview-video.mp4';
            \file_put_contents($filePath, 'preview');
            $bootstrap = new RuntimeBootstrap($root);
            $registryPath = $bootstrap->getNativeHostPreviewRegistryPath();
            \mkdir(\dirname($registryPath), 0777, true);
            \file_put_contents(
                $registryPath,
                (string) \json_encode([
                    'job-123' => [
                        'jobId' => 'job-123',
                        'path' => $filePath,
                        'token' => 'abc123',
                        'createdAt' => '2020-01-01T00:00:00+00:00',
                    ],
                ], JSON_THROW_ON_ERROR),
            );

            $registry = new NativeHostPreviewRegistryService($bootstrap);

            $resolved = $registry->resolve('job-123', 'abc123');

            self::assertSame($filePath, $resolved['path'] ?? null);
            self::assertSame('job-123', $resolved['jobId'] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRegisterReusesExistingTokenForSameJobAndPath(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_registry_reuse_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $filePath = $root . '/preview-video.mp4';
            \file_put_contents($filePath, 'preview');
            $registry = new NativeHostPreviewRegistryService(new RuntimeBootstrap($root));

            $firstPreview = $registry->register('job-123', $filePath, 38123);
            $secondPreview = $registry->register('job-123', $filePath, 38123);

            self::assertSame($firstPreview['token'], $secondPreview['token']);
            self::assertSame($firstPreview['previewUrl'], $secondPreview['previewUrl']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
