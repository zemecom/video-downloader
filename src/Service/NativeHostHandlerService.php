<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Throwable;
use YtdPhp\Dto\NativeHostRequest;
use YtdPhp\Dto\NativeHostResponse;
use YtdPhp\Exception\NativeHostException;

final readonly class NativeHostHandlerService
{
    public function __construct(
        private NativeHostJobManagerService $manager,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): NativeHostResponse
    {
        try {
            $request = NativeHostRequest::fromPayload($payload);

            return match ($request->action) {
                NativeHostRequest::START_DOWNLOAD => $this->manager->startDownload((string) $request->url, (string) $request->mode),
                NativeHostRequest::GET_JOB_STATUS => $this->manager->getJobStatus((string) $request->jobId),
                NativeHostRequest::CANCEL_DOWNLOAD => $this->manager->cancelDownload((string) $request->jobId),
                NativeHostRequest::LIST_RECENT_DOWNLOADS => $this->manager->listRecentDownloads(),
                NativeHostRequest::PREVIEW_RECENT_DOWNLOAD => $this->manager->previewRecentDownload((string) $request->entryId),
                NativeHostRequest::OPEN_RECENT_DOWNLOAD => $this->manager->openRecentDownload((string) $request->entryId),
                NativeHostRequest::REVEAL_RECENT_DOWNLOAD => $this->manager->revealRecentDownload((string) $request->entryId),
                NativeHostRequest::DELETE_RECENT_DOWNLOAD => $this->manager->deleteRecentDownload((string) $request->entryId),
                default => NativeHostResponse::error('invalid_payload', 'Invalid native host payload.'),
            };
        } catch (NativeHostException $exception) {
            return NativeHostResponse::error($exception->getResponseCode(), $exception->getMessage());
        } catch (Throwable) {
            return NativeHostResponse::error('unexpected_error', 'Unexpected native host error.');
        }
    }
}
