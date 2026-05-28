<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;
use YtdPhp\Bootstrap\RuntimeBootstrap;

use function fclose;
use function feof;
use function fgets;
use function file_exists;
use function getmypid;
use function in_array;
use function is_array;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;

final readonly class NativeHostJobRunnerService
{
    public function __construct(
        private RuntimeBootstrap $bootstrap,
        private NativeHostJobStateStore $store,
        private NativeHostProgressParserService $parser,
        private NativeHostRecentDownloadsStore $recentDownloads,
    ) {}

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

        $state['workerPid'] = getmypid();
        $state['updatedAt'] = $this->now();
        $this->store->write($jobId, $state);

        $command = [
            PHP_BINARY,
            $this->bootstrap->getPackageRoot() . '/bin/ytd',
        ];

        if ($mode === 'audio') {
            $command[] = '--audio';
        }

        $command[] = $url;

        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->bootstrap->getPackageRoot(),
            YtDlpClient::buildProcessEnv(),
        );

        if (!is_resource($process)) {
            $this->finalizeFailure($jobId, $state, 'Не удалось запустить ytd CLI.');

            return 1;
        }

        $status = proc_get_status($process);
        if (is_array($status) && isset($status['pid']) && is_int($status['pid'])) {
            $state['downloadPid'] = $status['pid'];
            $state['updatedAt'] = $this->now();
            $this->store->write($jobId, $state);
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                stream_set_blocking($pipes[$index], false);
            }
        }

        $buffers = [1 => '', 2 => ''];
        while (true) {
            if ($this->store->cancelRequested($jobId)) {
                $state = $this->store->read($jobId) ?? $state;
                $state['status'] = 'cancelling';
                $state['progressText'] = 'Останавливаю загрузку...';
                $state['canCancel'] = false;
                $state['updatedAt'] = $this->now();
                $this->store->write($jobId, $state);
            }

            $read = [];
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index]) && !feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }

            if ($read !== []) {
                $write = null;
                $except = null;
                stream_select($read, $write, $except, 0, 200000);
                foreach ($read as $stream) {
                    $index = $stream === $pipes[1] ? 1 : 2;
                    $chunk = stream_get_contents($stream);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    $buffers[$index] .= $chunk;
                    while (($newlinePosition = strpos($buffers[$index], "\n")) !== false) {
                        $line = substr($buffers[$index], 0, $newlinePosition + 1);
                        $buffers[$index] = substr($buffers[$index], $newlinePosition + 1);
                        $parsed = $this->parser->parse($line);
                        if ($parsed === null) {
                            continue;
                        }

                        $state = $this->store->read($jobId) ?? $state;
                        $state['status'] = $parsed['status'];
                        $state['progressPercent'] = $parsed['progressPercent'];
                        $state['progressText'] = $parsed['progressText'];
                        if (is_string($parsed['outputPath'] ?? null) && $parsed['outputPath'] !== '') {
                            $state['outputPath'] = $parsed['outputPath'];
                        }
                        $state['canCancel'] = !in_array($state['status'], ['completed', 'failed', 'cancelled'], true);
                        $state['updatedAt'] = $this->now();
                        $this->store->write($jobId, $state);
                    }
                }
            }

            $running = proc_get_status($process);
            if (!is_array($running) || !($running['running'] ?? false)) {
                break;
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                $rest = stream_get_contents($pipes[$index]);
                if (is_string($rest) && $rest !== '') {
                    $buffers[$index] .= $rest;
                }
                fclose($pipes[$index]);
            }
        }

        $exitCode = proc_close($process);
        $state = $this->store->read($jobId) ?? $state;

        if ($this->store->cancelRequested($jobId) || ($state['status'] ?? null) === 'cancelling') {
            $state['status'] = 'cancelled';
            $state['progressText'] = 'Загрузка отменена.';
            $state['canCancel'] = false;
            $state['updatedAt'] = $this->now();
            $this->store->write($jobId, $state);
            $this->store->clearCancelRequest($jobId);

            return 0;
        }

        if ($exitCode === 0) {
            $outputPath = $state['outputPath'] ?? null;
            if (is_string($outputPath) && $outputPath !== '' && file_exists($outputPath)) {
                $entry = $this->recentDownloads->append(
                    $outputPath,
                    (string) ($state['url'] ?? ''),
                    (string) ($state['mode'] ?? 'video'),
                );
                $state['recentDownloadId'] = $entry['id'] ?? null;
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

        $lastOutput = trim($buffers[1] . "\n" . $buffers[2]);
        $this->finalizeFailure($jobId, $state, $lastOutput !== '' ? $lastOutput : 'Загрузка завершилась с ошибкой.');

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

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DATE_ATOM);
    }
}
