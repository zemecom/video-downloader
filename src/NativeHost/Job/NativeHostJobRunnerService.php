<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Job;

use YtdPhp\NativeHost\Preview\NativeHostPreviewServerCoordinator;
use YtdPhp\NativeHost\Preview\NativeHostPreviewRegistryService;
use YtdPhp\NativeHost\Store\NativeHostJobStateStore;
use YtdPhp\NativeHost\Store\NativeHostPreviewServerStateStore;
use YtdPhp\NativeHost\Store\NativeHostRecentDownloadsStore;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\ProcessEnvironment;
use YtdPhp\Runtime\RuntimeBootstrap;

final readonly class NativeHostJobRunnerService
{
    private NativeHostPreviewRegistryService $previewRegistry;

    private NativeHostPreviewServerCoordinator $previewServerCoordinator;

    public function __construct(
        private RuntimeBootstrap $bootstrap,
        private NativeHostJobStateStore $store,
        private NativeHostProgressParserService $parser,
        private NativeHostRecentDownloadsStore $recentDownloads,
        ?NativeHostPreviewRegistryService $previewRegistry = null,
        ?NativeHostPreviewServerCoordinator $previewServerCoordinator = null,
    ) {
        $this->previewRegistry = $previewRegistry ?? new NativeHostPreviewRegistryService($bootstrap);
        $this->previewServerCoordinator = $previewServerCoordinator ?? new NativeHostPreviewServerCoordinator(
            $bootstrap,
            new NativeHostPreviewServerStateStore($bootstrap),
        );
    }

    public function run(string $jobId, string $url, string $mode = 'video'): int
    {
        $state = $this->store->read($jobId) ?? [
            'jobId' => $jobId,
            'url' => $url,
            'mode' => $mode,
            'status' => 'starting',
            'progressPercent' => null,
            'progressText' => $mode === 'audio' ? 'Подготавливаю загрузку аудио...' : 'Подготавливаю загрузку...',
            'canCancel' => true,
            'createdAt' => $this->now(),
            'updatedAt' => $this->now(),
        ];

        $state['mode'] = $mode;

        $state['workerPid'] = \getmypid();
        $state['updatedAt'] = $this->now();
        $this->store->write($jobId, $state);

        $command = [
            PHP_BINARY,
            $this->bootstrap->getPackageRoot() . '/bin/ytd',
        ];

        if ($mode === 'audio') {
            $command[] = '--audio';
        } elseif ($mode === 'video-fhd') {
            $command[] = '--quality=fhd';
        }

        $command[] = $url;

        $jobLogPath = $this->bootstrap->getNativeHostJobsDirectoryPath() . DIRECTORY_SEPARATOR . $jobId . '.log';
        $logHandle = @\fopen($jobLogPath, 'ab');
        if (\is_resource($logHandle)) {
            \fwrite($logHandle, \sprintf("[%s] Starting job %s\nCommand: %s\n\n", $this->now(), $jobId, \implode(' ', $command)));
        }

        $process = \proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->bootstrap->getPackageRoot(),
            \array_merge(ProcessEnvironment::build(), [
                'YTD_PROGRESS_NEWLINE' => '1',
                'YTD_RAW_PROGRESS' => '1',
            ]),
        );

        if (!\is_resource($process)) {
            $msg = 'Не удалось запустить ytd CLI.';
            if (\is_resource($logHandle)) {
                \fwrite($logHandle, \sprintf("\n[%s] ERROR: %s\n", $this->now(), $msg));
                \fclose($logHandle);
            }
            $this->finalizeFailure($jobId, $state, $msg);

            return 1;
        }

        $status = \proc_get_status($process);
        $state['downloadPid'] = $status['pid'];
        $state['updatedAt'] = $this->now();
        $this->store->write($jobId, $state);

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                \stream_set_blocking($pipes[$index], false);
            }
        }

        $buffers = [1 => '', 2 => ''];
        $terminationRequested = false;
        while (true) {
            $cancelRequested = $this->store->cancelRequested($jobId);
            if ($cancelRequested) {
                $state = $this->markCancelling($jobId, $state);
            }

            $read = [];
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && \is_resource($pipes[$index]) && !\feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                \stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $stream) {
                    $index = $stream === $pipes[1] ? 1 : 2;
                    $chunk = \stream_get_contents($stream);
                    if ($chunk === false) {
                        continue;
                    }
                    if ($chunk === '') {
                        continue;
                    }

                    if (\is_resource($logHandle)) {
                        \fwrite($logHandle, $chunk);
                    }

                    $buffers[$index] .= $chunk;
                    while (($newlinePosition = strpos($buffers[$index], "\n")) !== false) {
                        $line = substr($buffers[$index], 0, $newlinePosition + 1);
                        $buffers[$index] = substr($buffers[$index], $newlinePosition + 1);
                        $parsed = $this->parser->parse($line);
                        if ($parsed === null) {
                            continue;
                        }

                        $state = $this->applyParsedOutput($jobId, $state, $parsed);
                    }
                }
            }

            if ($cancelRequested && !$terminationRequested && !$this->hasOutputFile($state)) {
                $this->terminateProcess($process, $state);
                $terminationRequested = true;
            }

            $running = \proc_get_status($process);
            if (!$running['running']) {
                break;
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                $rest = \stream_get_contents($pipes[$index]);
                if (\is_string($rest) && $rest !== '') {
                    if (\is_resource($logHandle)) {
                        \fwrite($logHandle, $rest);
                    }
                    $buffers[$index] .= $rest;
                }
                \fclose($pipes[$index]);
            }
        }

        foreach ([1, 2] as $index) {
            $state = $this->consumeParsedOutput($jobId, $state, $buffers[$index]);
        }

        $exitCode = \proc_close($process);

        if (\is_resource($logHandle)) {
            \fwrite($logHandle, \sprintf("\n[%s] Process exited with code %d\n", $this->now(), $exitCode));
            \fclose($logHandle);
        }

        $state = $this->store->read($jobId) ?? $state;

        $outputPath = $this->outputPath($state);
        $hasOutputFile = $this->hasOutputFile($state);
        $cancelRequested = $this->store->cancelRequested($jobId) || ($state['status'] ?? null) === 'cancelling';

        if ($exitCode === 0 && ($hasOutputFile || !$cancelRequested)) {
            if (\is_string($outputPath) && $outputPath !== '' && \file_exists($outputPath)) {
                $entry = $this->recentDownloads->append(
                    $outputPath,
                    (string) ($state['url'] ?? ''),
                    (string) ($state['mode'] ?? 'video'),
                );
                $state['recentDownloadId'] = $entry['id'] ?? null;

                if (($state['mode'] ?? 'video') === 'video') {
                    try {
                        $port = $this->previewServerCoordinator->ensureRunning();
                        $preview = $this->previewRegistry->register($jobId, $outputPath, $port);
                        $state['previewReady'] = $preview['previewReady'] ?? false;
                        $state['previewUrl'] = $preview['previewUrl'] ?? null;
                    } catch (\Throwable $e) {
                        \error_log(
                            \sprintf("[%s] [ERROR] Failed to register preview for job %s: %s\n%s\n", \date('Y-m-d H:i:s'), $jobId, $e->getMessage(), $e->getTraceAsString()),
                            3,
                            $this->bootstrap->getNativeHostLogPath(),
                        );
                        $state['previewReady'] = false;
                        unset($state['previewUrl']);
                    }
                }
            }
            $state['status'] = 'completed';
            $state['progressPercent'] = 100.0;
            $state['progressText'] = 'Загрузка завершена.';
            $state['canCancel'] = false;
            $state['updatedAt'] = $this->now();
            $this->store->write($jobId, $state);
            $this->store->clearCancelRequest($jobId);

            return 0;
        }

        if ($cancelRequested) {
            $state['status'] = 'cancelled';
            $state['progressText'] = 'Загрузка отменена.';
            $state['canCancel'] = false;
            $state['updatedAt'] = $this->now();
            $this->store->write($jobId, $state);
            $this->store->clearCancelRequest($jobId);

            return 0;
        }

        $lastOutput = trim($buffers[1] . "\n" . $buffers[2]);
        $errorMessage = $lastOutput !== '' ? $lastOutput : 'Загрузка завершилась с ошибкой.';

        \error_log(
            \sprintf("[%s] [ERROR] Job %s failed (exit %d). See logs: %s\n", $this->now(), $jobId, $exitCode, $jobLogPath),
            3,
            $this->bootstrap->getNativeHostLogPath(),
        );

        $this->finalizeFailure($jobId, $state, $errorMessage);

        return $exitCode > 0 ? $exitCode : 1;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function finalizeFailure(string $jobId, array $state, string $message): void
    {
        $state['status'] = 'failed';
        $state['progressText'] = $message;
        $state['canCancel'] = false;
        $state['updatedAt'] = $this->now();
        $this->store->write($jobId, $state);
        $this->store->clearCancelRequest($jobId);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function consumeParsedOutput(string $jobId, array $state, string $buffer): array
    {
        if ($buffer === '') {
            return $state;
        }

        foreach (preg_split("/\r\n|\n|\r/", $buffer) ?: [] as $line) {
            $parsed = $this->parser->parse($line);
            if ($parsed === null) {
                continue;
            }

            $state = $this->applyParsedOutput($jobId, $state, $parsed);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function markCancelling(string $jobId, array $state): array
    {
        $state = $this->store->read($jobId) ?? $state;
        $state['status'] = 'cancelling';
        $state['progressText'] = 'Останавливаю загрузку...';
        $state['canCancel'] = false;
        $state['updatedAt'] = $this->now();
        $this->store->write($jobId, $state);

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function terminateProcess(mixed $process, array $state): void
    {
        $pid = $state['downloadPid'] ?? null;
        if (\is_int($pid) && $pid > 0) {
            new Process(['pkill', '-TERM', '-P', (string) $pid])->run();
        }

        \proc_terminate($process);
    }

    /**
     * @param array<string, mixed> $state
     * @param array{status:string, progressPercent:?float, progressText:string, outputPath?:string} $parsed
     * @return array<string, mixed>
     */
    private function applyParsedOutput(string $jobId, array $state, array $parsed): array
    {
        $state = $this->store->read($jobId) ?? $state;
        if (\is_string($parsed['outputPath'] ?? null) && $parsed['outputPath'] !== '') {
            $state['outputPath'] = $parsed['outputPath'];
        }

        if (!$this->isCancelling($jobId, $state)) {
            $state['status'] = $parsed['status'];
            $state['progressPercent'] = $parsed['progressPercent'];
            $state['progressText'] = $parsed['progressText'];
            $state['canCancel'] = !\in_array($state['status'], ['completed', 'failed', 'cancelled'], true);
        }

        $state['updatedAt'] = $this->now();
        $this->store->write($jobId, $state);

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isCancelling(string $jobId, array $state): bool
    {
        if ($this->store->cancelRequested($jobId)) {
            return true;
        }
        return ($state['status'] ?? null) === 'cancelling';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasOutputFile(array $state): bool
    {
        $outputPath = $this->outputPath($state);

        return $outputPath !== null && \file_exists($outputPath);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function outputPath(array $state): ?string
    {
        return \is_string($state['outputPath'] ?? null) && $state['outputPath'] !== ''
            ? $state['outputPath']
            : null;
    }

    private function now(): string
    {
        return new DateTimeImmutable()->format(DATE_ATOM);
    }
}
