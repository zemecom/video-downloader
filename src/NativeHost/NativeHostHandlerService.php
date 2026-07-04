<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost;

use Throwable;
use YtdPhp\NativeHost\NativeHostRequest;
use YtdPhp\NativeHost\NativeHostResponse;
use YtdPhp\NativeHost\NativeHostException;
use YtdPhp\NativeHost\Request\StartDownloadRequest;
use YtdPhp\NativeHost\Request\JobActionRequest;
use YtdPhp\NativeHost\Request\ListRecentDownloadsRequest;
use YtdPhp\NativeHost\Request\EntryActionRequest;
use YtdPhp\NativeHost\Request\LogClientErrorRequest;

final readonly class NativeHostHandlerService
{
    public function __construct(
        private NativeHostJobManagerService $manager,
        private NativeHostLogService $logger,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): NativeHostResponse
    {
        try {
            $request = NativeHostRequest::fromPayload($payload);

            return match (true) {
                $request instanceof StartDownloadRequest => $this->manager->startDownload($request->url, $request->mode),
                $request instanceof JobActionRequest => match ($request->action) {
                    NativeHostRequest::GET_JOB_STATUS => $this->manager->getJobStatus($request->jobId),
                    NativeHostRequest::CANCEL_DOWNLOAD => $this->manager->cancelDownload($request->jobId),
                    NativeHostRequest::FORCE_CANCEL_DOWNLOAD => $this->manager->forceCancelDownload($request->jobId),
                },
                $request instanceof ListRecentDownloadsRequest => $this->manager->listRecentDownloads(),
                $request instanceof EntryActionRequest => match ($request->action) {
                    NativeHostRequest::PREVIEW_RECENT_DOWNLOAD => $this->manager->previewRecentDownload($request->entryId),
                    NativeHostRequest::OPEN_RECENT_DOWNLOAD => $this->manager->openRecentDownload($request->entryId),
                    NativeHostRequest::REVEAL_RECENT_DOWNLOAD => $this->manager->revealRecentDownload($request->entryId),
                    NativeHostRequest::DELETE_RECENT_DOWNLOAD => $this->manager->deleteRecentDownload($request->entryId),
                },
                $request instanceof LogClientErrorRequest => $this->logClientError($request->errorMessage, $request->errorStack),
                default => NativeHostResponse::error('invalid_payload', 'Invalid native host payload.'),
            };
        } catch (NativeHostException $exception) {
            return NativeHostResponse::error($exception->getResponseCode(), $exception->getMessage());
        } catch (Throwable $e) {
            $this->logger->append('[ERROR] Unexpected native host error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            return NativeHostResponse::error('unexpected_error', 'Unexpected native host error.');
        }
    }

    private function logClientError(string $errorMessage, ?string $errorStack): NativeHostResponse
    {
        $this->logger->append('[CLIENT ERROR] ' . $errorMessage);
        if ($errorStack !== null) {
            $this->logger->append('[CLIENT ERROR STACK] ' . $errorStack);
        }

        return NativeHostResponse::success('client_error_logged', 'Client error logged.');
    }
}
