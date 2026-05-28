<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Dto\NativeHostRequest;
use YtdPhp\Dto\NativeHostResponse;

use function dirname;
use function file_exists;
use function is_int;
use function is_array;
use function is_string;
use function sprintf;
use function uniqid;

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

    public function __construct(
        private readonly RuntimeBootstrap $bootstrap,
        private readonly NativeHostJobStateStore $store,
        ?Closure $starter = null,
        ?Closure $signalSender = null,
        ?NativeHostRecentDownloadsStore $recentDownloads = null,
        ?Closure $opener = null,
        ?Closure $revealer = null,
    ) {
        $this->recentDownloads = $recentDownloads ?? new NativeHostRecentDownloadsStore($bootstrap);
        $this->starter = $starter ?? $this->makeDefaultStarter();
        $this->signalSender = $signalSender ?? $this->makeDefaultSignalSender();
        $this->opener = $opener ?? $this->makeDefaultOpener();
        $this->revealer = $revealer ?? $this->makeDefaultRevealer();
    }

    public function startDownload(string $url, string $mode = NativeHostRequest::MODE_VIDEO): NativeHostResponse
    {
        $jobId = 'job-' . uniqid();
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
        if (!is_array($state)) {
            return NativeHostResponse::error('job_not_found', 'Download job not found.', null, [
                'jobId' => $jobId,
            ]);
        }

        return NativeHostResponse::success('job_status', 'Job status loaded.', (string) ($state['url'] ?? null), $this->stateDetails($state));
    }

    public function cancelDownload(string $jobId): NativeHostResponse
    {
        $state = $this->store->read($jobId);
        if (!is_array($state)) {
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
        if (is_int($downloadPid) && $downloadPid > 0) {
            ($this->signalSender)($downloadPid);
        }

        return NativeHostResponse::success('cancel_requested', 'Cancellation requested.', (string) ($state['url'] ?? null), $this->stateDetails($state));
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
        ];
    }

    /**
     * @return Closure(string, string, string, string): void
     */
    private function makeDefaultStarter(): Closure
    {
        return function (string $jobId, string $url, string $mode, string $logPath): void {
            (new Filesystem())->mkdir(dirname($logPath));

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
            (new Process(['kill', '-TERM', (string) $pid]))->run();
            (new Process(['pkill', '-TERM', '-P', (string) $pid]))->run();
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
        if (!is_array($entry)) {
            return null;
        }

        $path = $entry['path'] ?? null;
        if (!is_string($path) || $path === '' || !file_exists($path)) {
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
}
