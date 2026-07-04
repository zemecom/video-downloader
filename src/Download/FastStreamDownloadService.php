<?php

declare(strict_types=1);

namespace YtdPhp\Download;

use YtdPhp\Download\YtDlp\YtDlpCommandBuilder;
use YtdPhp\Download\YtDlp\YtDlpGateway;
use YtdPhp\Download\YtDlp\YtDlpProgressRenderer;
use YtdPhp\Download\Format\FastStreamFormatPair;
use YtdPhp\Download\Process\DownloadProcessRunner;
use YtdPhp\Download\Process\DownloadOutputFormatter;
use YtdPhp\Download\Process\DownloadArtifactCleaner;
use YtdPhp\Download\Process\DownloadTemporaryStorage;
use LogicException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Shared\ConsoleLogger;

final readonly class FastStreamDownloadService
{
    private const int FAST_STREAM_MAX_ATTEMPTS = 3;

    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
        private ConsoleLogger $logger,
        private DownloadProcessRunner $processRunner,
        private DownloadTemporaryStorage $temporaryStorage,
        private DownloadArtifactCleaner $artifactCleaner,
        private DownloadOutputFormatter $outputFormatter,
    ) {}

    public function download(
        FastStreamFormatPair $pair,
        string $expectedFile,
        string $sourceUrl,
        DownloadOptions $options,
    ): DownloadResult {
        $tempDir = $this->temporaryStorage->createTemporaryDirectory('ytd_fast_');
        if ($tempDir === null) {
            return new DownloadResult('failed', 'tempdir_failed');
        }

        $filesystem = new Filesystem();
        $videoPath = $tempDir . '/video.' . $pair->video->extension;
        $audioPath = $tempDir . '/audio.' . $pair->audio->extension;

        try {
            $filesystem->mkdir(\dirname($expectedFile));
            $this->logger->info(\sprintf(
                '🚀 Начинаю быструю загрузку потоков: video=%s, audio=%s',
                $pair->video->formatId,
                $pair->audio->formatId,
            ));

            [$videoProcess, $audioProcess] = $this->downloadFastStreamFilesWithRetries(
                pair: $pair,
                videoPath: $videoPath,
                audioPath: $audioPath,
                sourceUrl: $sourceUrl,
                options: $options,
            );

            if (!$videoProcess->isSuccessful() || !$audioProcess->isSuccessful()) {
                $detail = $this->formatFastStreamFailureDetail($videoProcess, $audioProcess);
                $removedArtifacts = $this->artifactCleaner->cleanupFailedDownloadArtifacts($expectedFile);
                $this->logger->error('😭 Ой, во время быстрой загрузки потоков произошла ошибка.');
                if ($removedArtifacts > 0) {
                    $this->logger->warning('🧹 Удалены временные файлы загрузки: ' . $removedArtifacts);
                }
                $this->logger->error('❌ Подробности: ' . $detail);

                return new DownloadResult('failed', $detail);
            }

            $this->logger->info('🔗 Объединяю видео и аудио через ffmpeg...');
            $mergeProcess = $this->runFfmpegMerge($videoPath, $audioPath, $expectedFile, $options->forceOverwrites);
            if (!$mergeProcess->isSuccessful()) {
                $detail = $this->ytDlpClient->getProcessErrorDetail($mergeProcess, 'ffmpeg_merge_failed');
                $removedArtifacts = $this->artifactCleaner->cleanupFailedDownloadArtifacts($expectedFile);
                $this->logger->error('😭 Не удалось объединить видео и аудио через ffmpeg.');
                if ($removedArtifacts > 0) {
                    $this->logger->warning('🧹 Удалены временные файлы загрузки: ' . $removedArtifacts);
                }
                $this->logger->error('❌ Подробности: ' . $detail);

                return new DownloadResult('failed', $detail);
            }

            $size = \filesize($expectedFile);
            $this->outputFormatter->logOutputPath($expectedFile, $size !== false ? $size : null);
            $this->outputFormatter->logOutputDirectory($expectedFile);
            $this->logger->info('🎉 Ура! Загрузка завершена!');

            return new DownloadResult('completed');
        } finally {
            $filesystem->remove($tempDir);
        }
    }

    private function createRawStreamProcess(
        string $formatId,
        string $outputPath,
        string $sourceUrl,
        DownloadOptions $options,
    ): Process {
        $builder = new YtDlpCommandBuilder($sourceUrl);
        $builder->setProxy($options->proxy)->setInsecure($options->insecure);

        return $this->processRunner->createProcess($builder->buildForRawStreamDownload(
            $formatId,
            $outputPath,
            true,
            $options->concurrentFragments ?? $this->bootstrap->getConcurrentFragments(),
            $this->resolveProgressDelta($options->progressDelta),
        ));
    }

    /**
     * @return array{0:Process, 1:Process}
     */
    private function downloadFastStreamFilesWithRetries(
        FastStreamFormatPair $pair,
        string $videoPath,
        string $audioPath,
        string $sourceUrl,
        DownloadOptions $options,
    ): array {
        $videoProcess = null;
        $audioProcess = null;
        $retryVideo = true;
        $retryAudio = true;

        for ($attempt = 1; $attempt <= self::FAST_STREAM_MAX_ATTEMPTS; ++$attempt) {
            $running = [];

            if ($retryVideo) {
                $videoProcess = $this->createRawStreamProcess(
                    formatId: $pair->video->formatId,
                    outputPath: $videoPath,
                    sourceUrl: $sourceUrl,
                    options: $options,
                );
                $running['video'] = $videoProcess;
            }

            if ($retryAudio) {
                $audioProcess = $this->createRawStreamProcess(
                    formatId: $pair->audio->formatId,
                    outputPath: $audioPath,
                    sourceUrl: $sourceUrl,
                    options: $options,
                );
                $running['audio'] = $audioProcess;
            }

            foreach ($running as $process) {
                $process->start();
            }
            $this->waitForFastStreamProcesses($running);

            $retryVideo = $this->shouldRetryFastStreamProcess($videoProcess, $attempt);
            $retryAudio = $this->shouldRetryFastStreamProcess($audioProcess, $attempt);
            if (!$retryVideo && !$retryAudio) {
                break;
            }

            $this->logFastStreamRetryWarnings($retryVideo, $retryAudio, $attempt + 1);
        }

        if (!$videoProcess instanceof Process || !$audioProcess instanceof Process) {
            throw new LogicException('Fast stream processes were not initialized.');
        }

        return [$videoProcess, $audioProcess];
    }

    private function shouldRetryFastStreamProcess(?Process $process, int $attempt): bool
    {
        if (!$process instanceof Process || $process->isSuccessful() || $attempt >= self::FAST_STREAM_MAX_ATTEMPTS) {
            return false;
        }

        return $this->processRunner->isRetryableHttp403Failure($process);
    }

    private function logFastStreamRetryWarnings(bool $retryVideo, bool $retryAudio, int $attempt): void
    {
        $labels = [];
        if ($retryAudio) {
            $labels[] = 'audio';
        }
        if ($retryVideo) {
            $labels[] = 'video';
        }

        $this->logger->warning(\sprintf(
            '🔁 HTTP 403 на fast-потоке %s; повторяю попытку %d/%d с докачкой.',
            \implode('+', $labels),
            $attempt,
            self::FAST_STREAM_MAX_ATTEMPTS,
        ));
    }

    /**
     * @param array<string, Process> $processes
     */
    private function waitForFastStreamProcesses(array $processes): void
    {
        $processes = $this->orderFastStreamProcesses($processes);
        $rawMode = \getenv('YTD_RAW_PROGRESS') === '1';
        $progress = $rawMode ? null : new YtDlpProgressRenderer($this->logger, array_keys($processes));

        while ($this->hasRunningProcess($processes)) {
            foreach ($processes as $label => $process) {
                $this->flushProcessOutput($label, $process, $progress);
            }
            \usleep(100000);
        }

        foreach ($processes as $label => $process) {
            $this->flushProcessOutput($label, $process, $progress);
        }

        if ($progress !== null) {
            $progress->finish();
        }
    }

    /**
     * @param array<string, Process> $processes
     * @return array<string, Process>
     */
    private function orderFastStreamProcesses(array $processes): array
    {
        $ordered = [];
        foreach (['audio', 'video', 'download'] as $label) {
            if (isset($processes[$label])) {
                $ordered[$label] = $processes[$label];
            }
        }

        foreach ($processes as $label => $process) {
            $ordered[$label] ??= $process;
        }

        return $ordered;
    }

    /**
     * @param array<string, Process> $processes
     */
    private function hasRunningProcess(array $processes): bool
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                return true;
            }
        }

        return false;
    }

    private function flushProcessOutput(string $label, Process $process, ?YtDlpProgressRenderer $progress): void
    {
        $output = $process->getIncrementalOutput();
        if ($output !== '') {
            if ($progress !== null) {
                $progress->consume($label, $output);
            } else {
                $this->logger->raw($output);
            }
        }

        $errorOutput = $process->getIncrementalErrorOutput();
        if ($errorOutput !== '') {
            if ($progress !== null) {
                $progress->consume($label, $errorOutput);
            } else {
                $this->logger->raw($errorOutput);
            }
        }
    }

    private function formatFastStreamFailureDetail(Process $videoProcess, Process $audioProcess): string
    {
        $details = [];
        if (!$videoProcess->isSuccessful()) {
            $details[] = 'video: ' . $this->ytDlpClient->getProcessErrorDetail($videoProcess, 'video_stream_failed');
        }
        if (!$audioProcess->isSuccessful()) {
            $details[] = 'audio: ' . $this->ytDlpClient->getProcessErrorDetail($audioProcess, 'audio_stream_failed');
        }

        return $details !== [] ? \implode("\n", $details) : 'fast_stream_download_failed';
    }

    private function runFfmpegMerge(string $videoPath, string $audioPath, string $expectedFile, bool $forceOverwrites): Process
    {
        $process = $this->processRunner->createProcess([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            $forceOverwrites ? '-y' : '-n',
            '-i',
            $videoPath,
            '-i',
            $audioPath,
            '-map',
            '0:v:0',
            '-map',
            '1:a:0',
            '-c',
            'copy',
            $expectedFile,
        ]);
        $process->run();

        return $process;
    }

    private function resolveProgressDelta(?string $override): string
    {
        return \is_string($override) && $override !== ''
            ? $override
            : $this->bootstrap->getProgressDelta();
    }
}
