<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost;

use Throwable;
use YtdPhp\NativeHost\NativeHostRequest;
use YtdPhp\NativeHost\NativeHostResponse;
use YtdPhp\NativeHost\NativeHostException;

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

            return match ($request->action) {
                NativeHostRequest::START_DOWNLOAD => $this->manager->startDownload((string) $request->url, (string) $request->mode),
                NativeHostRequest::GET_JOB_STATUS => $this->manager->getJobStatus((string) $request->jobId),
                NativeHostRequest::CANCEL_DOWNLOAD => $this->manager->cancelDownload((string) $request->jobId),
                NativeHostRequest::FORCE_CANCEL_DOWNLOAD => $this->manager->forceCancelDownload((string) $request->jobId),
                NativeHostRequest::LIST_RECENT_DOWNLOADS => $this->manager->listRecentDownloads(),
                NativeHostRequest::PREVIEW_RECENT_DOWNLOAD => $this->manager->previewRecentDownload((string) $request->entryId),
                NativeHostRequest::OPEN_RECENT_DOWNLOAD => $this->manager->openRecentDownload((string) $request->entryId),
                NativeHostRequest::REVEAL_RECENT_DOWNLOAD => $this->manager->revealRecentDownload((string) $request->entryId),
                NativeHostRequest::DELETE_RECENT_DOWNLOAD => $this->manager->deleteRecentDownload((string) $request->entryId),
                NativeHostRequest::LOG_CLIENT_ERROR => $this->logClientError((string) $request->errorMessage, $request->errorStack),
                default => NativeHostResponse::error('invalid_payload', 'Invalid native host payload.'),
            };
        } catch (NativeHostException $exception) {
            return NativeHostResponse::error($exception->getResponseCode(), $exception->getMessage());
        } catch (Throwable) {
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
