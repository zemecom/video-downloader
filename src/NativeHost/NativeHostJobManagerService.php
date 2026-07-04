<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\NativeHost\NativeHostRequest;
use YtdPhp\NativeHost\NativeHostResponse;

final class NativeHostJobManagerService
{
    private readonly NativeHostRecentDownloadsStore $recentDownloads;

    /**
     * @var Closure(string, string, string, string): void
     */
    private readonly Closure $starter;

    /**
     * @var Closure(int): void
     */
    private readonly Closure $signalSender;

    /**
     * @var Closure(string): void
     */
    private readonly Closure $opener;

    /**
     * @var Closure(string): void
     */
    private readonly Closure $revealer;

    private readonly NativeHostPreviewRegistryService $previewRegistry;

    /**
     * @var Closure(): int
     */
    private readonly Closure $previewPortResolver;

    public function __construct(
        private readonly RuntimeBootstrap $bootstrap,
        private readonly NativeHostJobStateStore $store,
        ?Closure $starter = null,
        ?Closure $signalSender = null,
        ?NativeHostRecentDownloadsStore $recentDownloads = null,
        ?Closure $opener = null,
        ?Closure $revealer = null,
        ?NativeHostPreviewRegistryService $previewRegistry = null,
        ?Closure $previewPortResolver = null,
    ) {
        $this->recentDownloads = $recentDownloads ?? new NativeHostRecentDownloadsStore($bootstrap);
        $this->starter = $starter ?? $this->makeDefaultStarter();
        $this->signalSender = $signalSender ?? $this->makeDefaultSignalSender();
        $this->opener = $opener ?? $this->makeDefaultOpener();
        $this->revealer = $revealer ?? $this->makeDefaultRevealer();
        $this->previewRegistry = $previewRegistry ?? new NativeHostPreviewRegistryService($bootstrap);
        $this->previewPortResolver = $previewPortResolver ?? $this->makeDefaultPreviewPortResolver();
    }

    public function startDownload(string $url, string $mode = NativeHostRequest::MODE_VIDEO): NativeHostResponse
    {
        $jobId = 'job-' . \uniqid();
        $state = $this->baseState($jobId, $url, $mode);
        $this->store->write($jobId, $state);

        try {
            ($this->starter)($jobId, $url, $mode, $this->bootstrap->getNativeHostLogPath());
        } catch (Throwable) {
            $state['status'] = 'failed';
            $state['progressText'] = 'Не удалось запустить загрузку.';
            $state['canCancel'] = false;
            $state['updatedAt'] = $this->now();
            $this->store->write($jobId, $state);

            return NativeHostResponse::error(
                'spawn_failed',
                'Failed to start download process.',
                $url,
                $this->stateDetails($state),
            );
        }

        return NativeHostResponse::accepted($url, $this->stateDetails($state));
    }

    public function getJobStatus(string $jobId): NativeHostResponse
    {
        $state = $this->store->read($jobId);
        if (!\is_array($state)) {
            return NativeHostResponse::error('job_not_found', 'Download job not found.', null, [
                'jobId' => $jobId,
            ]);
        }

        return NativeHostResponse::success('job_status', 'Job status loaded.', (string) ($state['url'] ?? null), $this->stateDetails($state));
    }

    public function cancelDownload(string $jobId): NativeHostResponse
    {
        $state = $this->store->read($jobId);
        if (!\is_array($state)) {
            return NativeHostResponse::error('job_not_found', 'Download job not found.', null, [
                'jobId' => $jobId,
            ]);
        }

        $state['status'] = 'cancelling';
        $state['progressText'] = 'Останавливаю загрузку...';
        $state['canCancel'] = false;
        $state['updatedAt'] = $this->now();
        $this->store->write($jobId, $state);
        $this->store->requestCancel($jobId);

        $downloadPid = $state['downloadPid'] ?? null;
        if (\is_int($downloadPid) && $downloadPid > 0) {
            ($this->signalSender)($downloadPid);
        }

        return NativeHostResponse::success('cancel_requested', 'Cancellation requested.', (string) ($state['url'] ?? null), $this->stateDetails($state));
    }

    public function forceCancelDownload(string $jobId): NativeHostResponse
    {
        $state = $this->store->read($jobId);
        if (!\is_array($state)) {
            return NativeHostResponse::error('job_not_found', 'Download job not found.', null, [
                'jobId' => $jobId,
            ]);
        }

        if (!\in_array($state['status'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
            $state['status'] = 'cancelled';
            $state['progressText'] = 'Загрузка принудительно отменена.';
            $state['canCancel'] = false;
            $state['updatedAt'] = $this->now();
            $this->store->write($jobId, $state);

            $downloadPid = $state['downloadPid'] ?? null;
            if (\is_int($downloadPid) && $downloadPid > 0) {
                (new Process(['pkill', '-9', '-P', (string) $downloadPid]))->run();
                (new Process(['kill', '-9', (string) $downloadPid]))->run();
            }

            $workerPid = $state['workerPid'] ?? null;
            if (\is_int($workerPid) && $workerPid > 0) {
                (new Process(['kill', '-9', (string) $workerPid]))->run();
            }
        }
        
        $this->store->requestCancel($jobId);

        return NativeHostResponse::success('cancel_forced', 'Download forcibly cancelled.', (string) ($state['url'] ?? null), $this->stateDetails($state));
    }

    public function listRecentDownloads(): NativeHostResponse
    {
        return NativeHostResponse::success('recent_downloads', 'Recent downloads loaded.', null, [
            'items' => $this->recentDownloads->list(),
        ]);
    }

    public function openRecentDownload(string $entryId): NativeHostResponse
    {
        $entry = $this->resolveRecentDownload($entryId);
        if ($entry === null) {
            return NativeHostResponse::error('file_not_found', 'Downloaded file not found.', null, [
                'entryId' => $entryId,
            ]);
        }

        ($this->opener)((string) $entry['path']);

        return NativeHostResponse::success('recent_download_opened', 'Downloaded file opened.', null, [
            'entryId' => $entryId,
        ]);
    }

    public function previewRecentDownload(string $entryId): NativeHostResponse
    {
        $entry = $this->resolveRecentDownload($entryId);
        if ($entry === null) {
            return NativeHostResponse::error('file_not_found', 'Downloaded file not found.', null, [
                'entryId' => $entryId,
            ]);
        }

        if (($entry['mode'] ?? NativeHostRequest::MODE_VIDEO) !== NativeHostRequest::MODE_VIDEO) {
            return NativeHostResponse::error('unsupported_media', 'Preview is only available for video downloads.', null, [
                'entryId' => $entryId,
            ]);
        }

        $port = ($this->previewPortResolver)();
        $preview = $this->previewRegistry->register(
            'recent-' . $entryId . '-' . \uniqid(),
            (string) $entry['path'],
            $port,
        );

        return NativeHostResponse::success('recent_download_preview_ready', 'Downloaded video preview is ready.', null, [
            ...$preview,
            'entryId' => $entryId,
            'recentDownloadId' => $entryId,
            'filePath' => $entry['path'],
        ]);
    }

    public function revealRecentDownload(string $entryId): NativeHostResponse
    {
        $entry = $this->resolveRecentDownload($entryId);
        if ($entry === null) {
            return NativeHostResponse::error('file_not_found', 'Downloaded file not found.', null, [
                'entryId' => $entryId,
            ]);
        }

        ($this->revealer)((string) $entry['path']);

        return NativeHostResponse::success('recent_download_revealed', 'Downloaded file revealed.', null, [
            'entryId' => $entryId,
        ]);
    }

    public function deleteRecentDownload(string $entryId): NativeHostResponse
    {
        $entry = $this->recentDownloads->find($entryId);
        if (!\is_array($entry)) {
            return NativeHostResponse::error('file_not_found', 'Downloaded file not found.', null, [
                'entryId' => $entryId,
            ]);
        }

        $path = $entry['path'] ?? null;
        if (\is_string($path) && $path !== '' && \file_exists($path)) {
            (new Filesystem())->remove($path);
        }

        $this->recentDownloads->remove($entryId);

        return NativeHostResponse::success('recent_download_deleted', 'Downloaded file deleted.', null, [
            'entryId' => $entryId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseState(string $jobId, string $url, string $mode): array
    {
        $now = $this->now();

        return [
            'jobId' => $jobId,
            'url' => $url,
            'mode' => $mode,
            'status' => 'starting',
            'progressPercent' => null,
            'progressText' => $mode === NativeHostRequest::MODE_AUDIO
                ? 'Подготавливаю загрузку аудио...'
                : 'Подготавливаю загрузку...',
            'canCancel' => true,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function stateDetails(array $state): array
    {
        return [
            'jobId' => $state['jobId'] ?? null,
            'mode' => $state['mode'] ?? NativeHostRequest::MODE_VIDEO,
            'status' => $state['status'] ?? 'starting',
            'progressPercent' => $state['progressPercent'] ?? null,
            'progressText' => $state['progressText'] ?? 'Подготавливаю загрузку...',
            'canCancel' => $state['canCancel'] ?? false,
            'previewReady' => $state['previewReady'] ?? false,
            'previewUrl' => $state['previewUrl'] ?? null,
            'recentDownloadId' => $state['recentDownloadId'] ?? null,
            'outputPath' => $state['outputPath'] ?? null,
        ];
    }

    /**
     * @return Closure(string, string, string, string): void
     */
    private function makeDefaultStarter(): Closure
    {
        return function (string $jobId, string $url, string $mode, string $logPath): void {
            (new Filesystem())->mkdir(\dirname($logPath));

            $process = proc_open(
                [
                    PHP_BINARY,
                    $this->bootstrap->getPackageRoot() . '/bin/ytd-native-job',
                    '--job-id=' . $jobId,
                    '--url=' . $url,
                    '--mode=' . $mode,
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $logPath, 'a'],
                    2 => ['file', $logPath, 'a'],
                ],
                $pipes,
                $this->bootstrap->getPackageRoot(),
                null,
                ['create_new_console' => true],
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Failed to start native host job worker.');
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        };
    }

    /**
     * @return Closure(int): void
     */
    private function makeDefaultSignalSender(): Closure
    {
        return static function (int $pid): void {
            (new Process(['pkill', '-TERM', '-P', (string) $pid]))->run();
            (new Process(['kill', '-TERM', (string) $pid]))->run();
        };
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRecentDownload(string $entryId): ?array
    {
        $entry = $this->recentDownloads->find($entryId);
        if (!\is_array($entry)) {
            return null;
        }

        $path = $entry['path'] ?? null;
        if (!\is_string($path) || $path === '' || !\file_exists($path)) {
            return null;
        }

        return $entry;
    }

    /**
     * @return Closure(string): void
     */
    private function makeDefaultOpener(): Closure
    {
        return static function (string $path): void {
            $process = new Process(['open', $path]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException('Failed to open downloaded file.');
            }
        };
    }

    /**
     * @return Closure(string): void
     */
    private function makeDefaultRevealer(): Closure
    {
        return static function (string $path): void {
            $process = new Process(['open', '-R', $path]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException('Failed to reveal downloaded file.');
            }
        };
    }

    /**
     * @return Closure(): int
     */
    private function makeDefaultPreviewPortResolver(): Closure
    {
        $coordinator = new NativeHostPreviewServerCoordinator(
            $this->bootstrap,
            new NativeHostPreviewServerStateStore($this->bootstrap),
        );

        return static fn(): int => $coordinator->ensureRunning();
    }
}
