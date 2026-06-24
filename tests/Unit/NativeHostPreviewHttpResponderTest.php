<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\NativeHost\NativeHostPreviewHttpResponder;
use YtdPhp\NativeHost\NativeHostPreviewRegistryService;

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

    public function testRespondReturnsNotFoundForExpiredToken(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_http_expired_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $filePath = $root . '/preview-video.mp4';
            \file_put_contents($filePath, 'abcdefghijklmnopqrstuvwxyz');

            $registry = new NativeHostPreviewRegistryService(
                new RuntimeBootstrap($root),
                30,
                static fn(): DateTimeImmutable => new DateTimeImmutable('2026-05-30T12:00:00+00:00'),
            );
            $preview = $registry->register('job-123', $filePath, 38123);

            $expiredRegistry = new NativeHostPreviewRegistryService(
                new RuntimeBootstrap($root),
                30,
                static fn(): DateTimeImmutable => new DateTimeImmutable('2026-05-30T12:01:00+00:00'),
            );
            $responder = new NativeHostPreviewHttpResponder($expiredRegistry);

            $response = $responder->respond('GET', '/preview/job-123?token=' . $preview['token'], []);

            self::assertSame(404, $response['status']);
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

        $registry = new NativeHostPreviewRegistryService(
            new RuntimeBootstrap($root),
            3600,
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-05-30T12:00:00+00:00'),
        );
        $preview = $registry->register('job-123', $filePath, 38123);

        return [$preview, new NativeHostPreviewHttpResponder($registry), $filePath];
    }
}
