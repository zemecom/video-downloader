<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use YtdPhp\NativeHost\Protocol\Request\EntryActionRequest;
use YtdPhp\NativeHost\Protocol\Request\JobActionRequest;
use YtdPhp\NativeHost\Protocol\Request\ActionRequest;
use YtdPhp\NativeHost\Protocol\Request\StartDownloadRequest;
use PHPUnit\Framework\TestCase;
use YtdPhp\NativeHost\Protocol\NativeHostRequest;
use YtdPhp\NativeHost\Protocol\NativeHostException;

final class NativeHostRequestTest extends TestCase
{
    public function testFromPayloadAcceptsStartDownloadActionWithUrl(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'start_download',
            'url' => 'https://example.com/watch?v=42',
        ]);

        self::assertInstanceOf(StartDownloadRequest::class, $request);
        self::assertSame('start_download', $request->action);
        self::assertSame('https://example.com/watch?v=42', $request->url);
        self::assertSame('video', $request->mode);
    }

    public function testFromPayloadAcceptsAudioModeForStartDownloadAction(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'start_download',
            'url' => 'https://example.com/watch?v=42',
            'mode' => 'audio',
        ]);

        self::assertInstanceOf(StartDownloadRequest::class, $request);
        self::assertSame('audio', $request->mode);
    }

    public function testFromPayloadAcceptsVideoFhdModeForStartDownloadAction(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'start_download',
            'url' => 'https://example.com/watch?v=42',
            'mode' => 'video-fhd',
        ]);

        self::assertInstanceOf(StartDownloadRequest::class, $request);
        self::assertSame('video-fhd', $request->mode);
    }


    public function testFromPayloadAcceptsStatusActionWithJobId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'get_job_status',
            'jobId' => 'job-123',
        ]);

        self::assertInstanceOf(JobActionRequest::class, $request);
        self::assertSame('get_job_status', $request->action);
        self::assertSame('job-123', $request->jobId);
    }

    public function testFromPayloadAcceptsListRecentDownloadsAction(): void
    {
        $payload = [
            'action' => 'list_recent_downloads',
        ];

        $request = NativeHostRequest::fromPayload($payload);
        $this->assertInstanceOf(ActionRequest::class, $request);
        $this->assertSame('list_recent_downloads', $request->action);
    }

    public function testFromPayloadAcceptsOpenRecentDownloadActionWithEntryId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'open_recent_download',
            'entryId' => 'download-123',
        ]);

        self::assertInstanceOf(EntryActionRequest::class, $request);
        self::assertSame('open_recent_download', $request->action);
        self::assertSame('download-123', $request->entryId);
    }

    public function testFromPayloadAcceptsPreviewRecentDownloadActionWithEntryId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'preview_recent_download',
            'entryId' => 'download-123',
        ]);

        self::assertInstanceOf(EntryActionRequest::class, $request);
        self::assertSame('preview_recent_download', $request->action);
        self::assertSame('download-123', $request->entryId);
    }

    public function testFromPayloadAcceptsDeleteRecentDownloadActionWithEntryId(): void
    {
        $request = NativeHostRequest::fromPayload([
            'action' => 'delete_recent_download',
            'entryId' => 'download-123',
        ]);

        self::assertInstanceOf(EntryActionRequest::class, $request);
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
