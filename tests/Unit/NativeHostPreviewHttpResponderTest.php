<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\NativeHost\Preview\NativeHostPreviewHttpResponder;
use YtdPhp\NativeHost\Preview\NativeHostPreviewRegistryService;
use YtdPhp\Runtime\RuntimeBootstrap;

final class NativeHostPreviewHttpResponderTest extends TestCase
{
    public function testRespondReturnsWholeFileForGetRequest(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_get_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            [$preview, $responder] = $this->buildPreviewFixture($root, 'abcdefghijklmnopqrstuvwxyz');

            $response = $responder->respond('GET', '/preview/job-123?token=' . $preview['token'], []);

            self::assertSame(200, $response['status']);
            self::assertSame('abcdefghijklmnopqrstuvwxyz', $response['body']);
            self::assertSame('bytes', $response['headers']['Accept-Ranges'] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRespondUsesCorrectContentTypeForAudio(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_audio_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $filePath = $root . '/preview-audio.mp3';
            \file_put_contents($filePath, 'audio-bytes');

            $registry = new NativeHostPreviewRegistryService(new RuntimeBootstrap($root));
            $preview = $registry->register('job-123', $filePath, 38123);
            $responder = new NativeHostPreviewHttpResponder($registry);

            $response = $responder->respond('GET', '/preview/job-123?token=' . $preview['token'], []);

            self::assertSame(200, $response['status']);
            self::assertSame('audio/mpeg', $response['headers']['Content-Type'] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRespondReturnsHeadersOnlyForHeadRequest(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_head_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            [$preview, $responder] = $this->buildPreviewFixture($root, 'abcdefghijklmnopqrstuvwxyz');

            $response = $responder->respond('HEAD', '/preview/job-123?token=' . $preview['token'], []);

            self::assertSame(200, $response['status']);
            self::assertSame('', $response['body']);
            self::assertSame('26', $response['headers']['Content-Length'] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRespondSupportsByteRanges(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_range_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            [$preview, $responder] = $this->buildPreviewFixture($root, 'abcdefghijklmnopqrstuvwxyz');

            $response = $responder->respond('GET', '/preview/job-123?token=' . $preview['token'], [
                'range' => 'bytes=5-9',
            ]);

            self::assertSame(206, $response['status']);
            self::assertSame('fghij', $response['body']);
            self::assertSame('bytes 5-9/26', $response['headers']['Content-Range'] ?? null);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRespondReturnsNotFoundForUnknownToken(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_unknown_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            [, $responder] = $this->buildPreviewFixture($root, 'abcdefghijklmnopqrstuvwxyz');

            $response = $responder->respond('GET', '/preview/job-123?token=does-not-match', []);

            self::assertSame(404, $response['status']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRespondReturnsFileForLegacyPreviewRecordRegardlessOfCreatedAt(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_legacy_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $filePath = $root . '/preview-video.mp4';
            \file_put_contents($filePath, 'abcdefghijklmnopqrstuvwxyz');
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

            $responder = new NativeHostPreviewHttpResponder(new NativeHostPreviewRegistryService($bootstrap));

            $response = $responder->respond('GET', '/preview/job-123?token=abc123', []);

            self::assertSame(200, $response['status']);
            self::assertSame('abcdefghijklmnopqrstuvwxyz', $response['body']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRespondReturnsNotFoundWhenFileWasDeleted(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_missing_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            [$preview, $responder, $filePath] = $this->buildPreviewFixture($root, 'abcdefghijklmnopqrstuvwxyz');
            unlink($filePath);

            $response = $responder->respond('GET', '/preview/job-123?token=' . $preview['token'], []);

            self::assertSame(404, $response['status']);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: NativeHostPreviewHttpResponder, 2: string}
     */
    private function buildPreviewFixture(string $root, string $body): array
    {
        $filePath = $root . '/preview-video.mp4';
        \file_put_contents($filePath, $body);

        $registry = new NativeHostPreviewRegistryService(new RuntimeBootstrap($root));
        $preview = $registry->register('job-123', $filePath, 38123);

        return [$preview, new NativeHostPreviewHttpResponder($registry), $filePath];
    }
}
