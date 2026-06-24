<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\NativeHost\NativeHostRequest;
use YtdPhp\NativeHost\NativeHostException;

final class NativeHostRequestTest extends TestCase
{
    public function testFromPayloadAcceptsStartDownloadActionWithUrl(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'start_download',
            'url' => 'https://example.com/watch?v=42',
        ]);

        self::assertSame('start_download', $request->action);
        self::assertSame('https://example.com/watch?v=42', $request->url);
        self::assertNull($request->jobId);
        self::assertSame('video', $request->mode);
    }

    public function testFromPayloadAcceptsAudioModeForStartDownloadAction(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'start_download',
            'url' => 'https://example.com/watch?v=42',
            'mode' => 'audio',
        ]);

        self::assertSame('audio', $request->mode);
    }

    public function testFromPayloadAcceptsStatusActionWithJobId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'get_job_status',
            'jobId' => 'job-123',
        ]);

        self::assertSame('get_job_status', $request->action);
        self::assertSame('job-123', $request->jobId);
        self::assertNull($request->url);
    }

    public function testFromPayloadAcceptsListRecentDownloadsAction(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'list_recent_downloads',
        ]);

        self::assertSame('list_recent_downloads', $request->action);
    }

    public function testFromPayloadAcceptsOpenRecentDownloadActionWithEntryId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'open_recent_download',
            'entryId' => 'download-123',
        ]);

        self::assertSame('open_recent_download', $request->action);
        self::assertSame('download-123', $request->entryId);
    }

    public function testFromPayloadAcceptsPreviewRecentDownloadActionWithEntryId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'preview_recent_download',
            'entryId' => 'download-123',
        ]);

        self::assertSame('preview_recent_download', $request->action);
        self::assertSame('download-123', $request->entryId);
    }

    public function testFromPayloadAcceptsDeleteRecentDownloadActionWithEntryId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'delete_recent_download',
            'entryId' => 'download-123',
        ]);

        self::assertSame('delete_recent_download', $request->action);
        self::assertSame('download-123', $request->entryId);
    }

    public function testFromPayloadRejectsCancelActionWithoutJobId(): void
    {
        $this->expectException(NativeHostException::class);

        NativeHostRequest::fromPayload([
            'action' => 'cancel_download',
        ]);
    }

    public function testFromPayloadRejectsUnsupportedDownloadMode(): void
    {
        $this->expectException(NativeHostException::class);

        NativeHostRequest::fromPayload([
            'action' => 'start_download',
            'url' => 'https://example.com/watch?v=42',
            'mode' => 'playlist',
        ]);
    }

    public function testFromPayloadRejectsRecentDownloadActionWithoutEntryId(): void
    {
        $this->expectException(NativeHostException::class);

        NativeHostRequest::fromPayload([
            'action' => 'reveal_recent_download',
        ]);
    }
}
