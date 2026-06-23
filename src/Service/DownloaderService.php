<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use LogicException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Dto\DownloadResult;
use YtdPhp\Dto\FastStreamFormatPair;

use function basename;
use function dirname;
use function file_exists;
use function file_put_contents;
use function filesize;
use function floor;
use function getenv;
use function implode;
use function intdiv;
use function is_dir;
use function is_file;
use function is_array;
use function is_string;
use function json_decode;
use function log;
use function microtime;
use function mkdir;
use function number_format;
use function pathinfo;
use function preg_split;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

final readonly class DownloaderService
{
    private const int DOWNLOAD_MAX_ATTEMPTS = 3;
    private const int FAST_STREAM_MAX_ATTEMPTS = 3;

    public function __construct(
        private YtDlpClient $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
        private ConsoleLogger $logger,
        private InputPrompter $prompter,
        private AutomaticFormatResolver $automaticFormatResolver = new AutomaticFormatResolver(),
        private FastStreamFormatResolver $fastStreamFormatResolver = new FastStreamFormatResolver(),
    ) {}

    public function formatSize(int $sizeBytes): string
    {
        if ($sizeBytes === 0) {
            return '0B';
        }

        $units = ['B', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
        $index = (int) floor(log((float) $sizeBytes, 1024));
        $power = 1024 ** $index;
        $size = number_format($sizeBytes / $power, 2, '.', '');
        $size = preg_replace('/\.00$/', '', (string) $size);

        return $size . $units[$index];
    }

    public function downloadVideo(
        string $videoUrl,
        string $formatCode,
        ?string $proxy = null,
        bool $insecure = false,
        string $outputFormat = 'mkv',
        bool $dryRun = false,
        ?int $concurrentFragments = null,
        ?string $downloadDir = null,
        ?bool $progressNewline = null,
        ?string $progressDelta = null,
        bool $emitElapsedRuntime = true,
    ): DownloadResult {
        return $this->withElapsedRuntime(
            fn(): DownloadResult => $this->downloadVideoInternal(
                $videoUrl,
                $formatCode,
                $proxy,
                $insecure,
                $outputFormat,
                $dryRun,
                $concurrentFragments,
                $downloadDir,
                $progressNewline,
                $progressDelta,
            ),
            $emitElapsedRuntime,
        );
    }

    private function downloadVideoInternal(
        string $videoUrl,
        string $formatCode,
        ?string $proxy = null,
        bool $insecure = false,
        string $outputFormat = 'mkv',
        bool $dryRun = false,
        ?int $concurrentFragments = null,
        ?string $downloadDir = null,
        ?bool $progressNewline = null,
        ?string $progressDelta = null,
    ): DownloadResult {
        $basePath = $this->bootstrap->getDownloadBasePath($videoUrl, $downloadDir);
        $outputTemplate = $basePath . '/%(title)s.%(ext)s';

        $this->logger->info('⏳ Получаю метаданные...');
        $builder = new YtDlpCommandBuilder($videoUrl);
        $builder->setProxy($proxy)->setInsecure($insecure);
        $process = $this->ytDlpClient->runCaptured($builder->buildForMetadata());
        if (!$process->isSuccessful()) {
            $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'metadata_failed');
            $this->logger->error('😭 Ой, во время загрузки или получения метаданных произошла ошибка.');
            $this->logger->error('❌ Подробности: ' . $detail);

            return new DownloadResult('failed', $detail);
        }

        $tempJsonPath = tempnam(sys_get_temp_dir(), 'ytd_');
        if ($tempJsonPath === false) {
            return new DownloadResult('failed', 'tempfile_failed');
        }
        file_put_contents($tempJsonPath, $process->getOutput());

        $metadata = json_decode($process->getOutput(), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $resolvedFormatCode = $this->resolveRequestedFormatCode($formatCode, $metadata, $videoUrl, $outputFormat);

        try {
            $this->logger->info('🔍 Проверяю наличие файла...');
            $expectedFile = $this->ytDlpClient->getExpectedFilename(
                null,
                $resolvedFormatCode,
                $outputTemplate,
                $proxy,
                $insecure,
                $tempJsonPath,
                $outputFormat,
            );
            if (is_string($expectedFile) && $expectedFile !== '') {
                $expectedFile = $this->bootstrap->sanitizeOutputFilename($expectedFile);
            }

            if ($dryRun) {
                $this->logger->info('🧪 Режим dry-run: показываю результат preflight без загрузки.');
                if (is_string($expectedFile) && $expectedFile !== '') {
                    $this->logOutputPath($expectedFile);
                }
                if (is_string($expectedFile) && file_exists($expectedFile)) {
                    $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                    $this->logger->info('↪️ В обычном режиме был бы показан вопрос о перезаписи.');
                } else {
                    $this->logger->info('⬇️ Будет скачано в формате: ' . $resolvedFormatCode);
                }

                return new DownloadResult('completed', 'dry_run');
            }

            $forceOverwrites = false;
            if (is_string($expectedFile) && $expectedFile !== '' && file_exists($expectedFile)) {
                $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                $choice = strtolower(trim($this->prompter->ask('🔄 Перезаписать? [y/N]: ')));
                if ($choice !== 'y') {
                    $this->logExistingOutputTarget($expectedFile);
                    $this->logger->info('⏭️ Пропускаю загрузку по выбору пользователя.');

                    return new DownloadResult('skipped', 'user_declined_overwrite');
                }
                $forceOverwrites = true;
            }

            $downloadTarget = $expectedFile ?? $outputTemplate;

            return $this->downloadFromInfoJson(
                $tempJsonPath,
                $downloadTarget,
                $resolvedFormatCode,
                $proxy,
                $insecure,
                $outputFormat,
                $forceOverwrites,
                $expectedFile,
                true,
                $videoUrl,
                $concurrentFragments,
                $progressNewline,
                $progressDelta,
            );
        } finally {
            if (file_exists($tempJsonPath)) {
                unlink($tempJsonPath);
            }
        }
    }

    public function downloadVideoFast(
        string $videoUrl,
        string $qualityPreset,
        ?string $proxy = null,
        bool $insecure = false,
        string $outputFormat = 'mkv',
        bool $dryRun = false,
        ?int $concurrentFragments = null,
        ?string $downloadDir = null,
        ?string $progressDelta = null,
        bool $emitElapsedRuntime = true,
    ): DownloadResult {
        return $this->withElapsedRuntime(
            fn(): DownloadResult => $this->downloadVideoFastInternal(
                $videoUrl,
                $qualityPreset,
                $proxy,
                $insecure,
                $outputFormat,
                $dryRun,
                $concurrentFragments,
                $downloadDir,
                $progressDelta,
            ),
            $emitElapsedRuntime,
        );
    }

    private function downloadVideoFastInternal(
        string $videoUrl,
        string $qualityPreset,
        ?string $proxy = null,
        bool $insecure = false,
        string $outputFormat = 'mkv',
        bool $dryRun = false,
        ?int $concurrentFragments = null,
        ?string $downloadDir = null,
        ?string $progressDelta = null,
    ): DownloadResult {
        $basePath = $this->bootstrap->getDownloadBasePath($videoUrl, $downloadDir);
        $outputTemplate = $basePath . '/%(title)s.%(ext)s';

        $this->logger->info('⏳ Получаю метаданные...');
        $builder = new YtDlpCommandBuilder($videoUrl);
        $builder->setProxy($proxy)->setInsecure($insecure);
        $process = $this->ytDlpClient->runCaptured($builder->buildForMetadata());
        if (!$process->isSuccessful()) {
            $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'metadata_failed');
            $this->logger->error('😭 Ой, во время загрузки или получения метаданных произошла ошибка.');
            $this->logger->error('❌ Подробности: ' . $detail);

            return new DownloadResult('failed', $detail);
        }

        $tempJsonPath = tempnam(sys_get_temp_dir(), 'ytd_');
        if ($tempJsonPath === false) {
            return new DownloadResult('failed', 'tempfile_failed');
        }
        file_put_contents($tempJsonPath, $process->getOutput());

        $metadata = json_decode($process->getOutput(), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        try {
            $pair = $this->fastStreamFormatResolver->resolve(
                $qualityPreset,
                $metadata,
                $this->bootstrap->isYoutubeUrl($videoUrl),
                $outputFormat,
            );
            if ($pair === null) {
                $this->logger->warning('⚠️ Не удалось подобрать отдельные video/audio потоки для `--fast`; использую обычную загрузку.');

                return $this->downloadVideo(
                    $videoUrl,
                    $qualityPreset,
                    $proxy,
                    $insecure,
                    $outputFormat,
                    $dryRun,
                    $concurrentFragments,
                    $downloadDir,
                    true,
                    $progressDelta,
                    false,
                );
            }

            $resolvedFormatCode = $this->resolveRequestedFormatCode($qualityPreset, $metadata, $videoUrl, $outputFormat);
            $expectedFile = $this->ytDlpClient->getExpectedFilename(
                null,
                $resolvedFormatCode,
                $outputTemplate,
                $proxy,
                $insecure,
                $tempJsonPath,
                $outputFormat,
            ) ?? $this->buildFallbackExpectedOutputPath($metadata, $basePath, $outputFormat);
            $expectedFile = $this->replaceOutputExtension($this->bootstrap->sanitizeOutputFilename($expectedFile), $outputFormat);

            if ($dryRun) {
                $this->logger->info('🧪 Режим dry-run: показываю результат preflight без загрузки.');
                $this->logOutputPath($expectedFile);
                if (file_exists($expectedFile)) {
                    $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                    $this->logger->info('↪️ В обычном режиме был бы показан вопрос о перезаписи.');
                } else {
                    $this->logger->info(sprintf(
                        '⬇️ Быстрый режим скачает video=%s и audio=%s параллельно.',
                        $pair->video->formatId,
                        $pair->audio->formatId,
                    ));
                }

                return new DownloadResult('completed', 'dry_run');
            }

            $forceOverwrites = false;
            if (file_exists($expectedFile)) {
                $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                $choice = strtolower(trim($this->prompter->ask('🔄 Перезаписать? [y/N]: ')));
                if ($choice !== 'y') {
                    $this->logExistingOutputTarget($expectedFile);
                    $this->logger->info('⏭️ Пропускаю загрузку по выбору пользователя.');

                    return new DownloadResult('skipped', 'user_declined_overwrite');
                }
                $forceOverwrites = true;
            }

            return $this->downloadFastStreams(
                $pair,
                $expectedFile,
                $proxy,
                $insecure,
                $forceOverwrites,
                $videoUrl,
                $concurrentFragments,
                $progressDelta,
            );
        } finally {
            if (file_exists($tempJsonPath)) {
                unlink($tempJsonPath);
            }
        }
    }

    /**
     * @param callable(): DownloadResult $operation
     */
    private function withElapsedRuntime(callable $operation, bool $emitElapsedRuntime): DownloadResult
    {
        $startedAt = microtime(true);

        try {
            return $operation();
        } finally {
            if ($emitElapsedRuntime) {
                $this->logger->info('⏱️ Время работы: ' . $this->formatElapsedRuntime(microtime(true) - $startedAt));
            }
        }
    }

    private function formatElapsedRuntime(float $seconds): string
    {
        if ($seconds < 1.0) {
            return number_format($seconds, 2, '.', '') . 'с';
        }

        $totalSeconds = (int) floor($seconds);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $remainingSeconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%dч %02dм %02dс', $hours, $minutes, $remainingSeconds);
        }

        if ($minutes > 0) {
            return sprintf('%dм %02dс', $minutes, $remainingSeconds);
        }

        return sprintf('%dс', $remainingSeconds);
    }

    public function downloadFromInfoJson(
        string $infoJsonPath,
        string $outputTemplate,
        string $formatCode = 'best',
        ?string $proxy = null,
        bool $insecure = false,
        string $outputFormat = 'mkv',
        bool $forceOverwrites = false,
        ?string $expectedFile = null,
        bool $emitLogs = true,
        ?string $sourceUrl = null,
        ?int $concurrentFragments = null,
        ?bool $progressNewline = null,
        ?string $progressDelta = null,
    ): DownloadResult {
        $expectedFile ??= $this->ytDlpClient->getExpectedFilename(
            null,
            $formatCode,
            $outputTemplate,
            $proxy,
            $insecure,
            $infoJsonPath,
            $outputFormat,
        );

        if (is_string($expectedFile) && $expectedFile !== '' && file_exists($expectedFile) && !$forceOverwrites) {
            if ($emitLogs) {
                $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                $this->logExistingOutputTarget($expectedFile);
            }

            return new DownloadResult('skipped', 'file_exists');
        }

        $builder = new YtDlpCommandBuilder($sourceUrl);
        $builder->setProxy($proxy)->setInsecure($insecure)->loadInfoJson($infoJsonPath);
        if ($forceOverwrites) {
            $builder->addArg('--force-overwrites');
        }

        $command = $builder->buildForDownload(
            $formatCode,
            $outputTemplate,
            $outputFormat,
            $this->resolveLineBufferedProgress($progressNewline),
            $concurrentFragments ?? $this->bootstrap->getConcurrentFragments(),
            $this->resolveProgressDelta($progressDelta),
        );
        if ($emitLogs) {
            $this->logger->info('🚀 Начинаю загрузку...');
        }
        $process = $this->runDownloadWithRetries($command, $emitLogs);

        return $this->finalizeProcessResult($process, $expectedFile, $emitLogs);
    }

    /**
     * @param list<string> $command
     */
    private function runDownloadWithRetries(array $command, bool $emitLogs): Process
    {
        $process = null;

        for ($attempt = 1; $attempt <= self::DOWNLOAD_MAX_ATTEMPTS; ++$attempt) {
            $process = $emitLogs
                ? $this->runLiveDownloadWithProgress($command)
                : $this->ytDlpClient->runCaptured($command);

            if (!$this->shouldRetryDownloadProcess($process, $attempt)) {
                break;
            }

            if ($emitLogs) {
                $this->logger->warning(sprintf(
                    '🔁 HTTP 403 во время загрузки; повторяю попытку %d/%d с докачкой.',
                    $attempt + 1,
                    self::DOWNLOAD_MAX_ATTEMPTS,
                ));
            }
        }

        if (!$process instanceof Process) {
            throw new LogicException('Download process was not initialized.');
        }

        return $process;
    }

    private function shouldRetryDownloadProcess(Process $process, int $attempt): bool
    {
        if ($process->isSuccessful() || $attempt >= self::DOWNLOAD_MAX_ATTEMPTS) {
            return false;
        }

        return $this->isRetryableHttp403Failure($process);
    }

    /**
     * @param list<string> $command
     */
    private function runLiveDownloadWithProgress(array $command): Process
    {
        $progress = new YtDlpProgressRenderer($this->logger, ['download']);
        $process = new Process($command);
        $process->setTimeout(null);
        $process->setEnv(YtDlpClient::buildProcessEnv());
        $process->run(function (string $type, string $buffer) use ($progress): void {
            $progress->consume('download', $buffer);
        });
        $progress->finish();

        return $process;
    }

    /**
     * @param array<mixed> $metadata
     */
    public function resolveRequestedFormatCode(
        string $formatCode,
        array $metadata,
        ?string $sourceUrl = null,
        string $outputFormat = 'mkv',
    ): string {
        return $this->automaticFormatResolver->resolve(
            $formatCode,
            $metadata,
            is_string($sourceUrl) && $sourceUrl !== '' && $this->bootstrap->isYoutubeUrl($sourceUrl),
            $outputFormat,
        );
    }

    public function createPlaylistDownloadProcess(
        string $infoJsonPath,
        string $outputPath,
        ?string $proxy,
        bool $insecure,
        string $formatCode,
        string $outputFormat,
        bool $forceOverwrites,
        ?string $sourceUrl = null,
        ?int $concurrentFragments = null,
        ?bool $progressNewline = null,
        ?string $progressDelta = null,
    ): Process {
        $builder = new YtDlpCommandBuilder($sourceUrl);
        $builder->setProxy($proxy)->setInsecure($insecure)->loadInfoJson($infoJsonPath);
        if ($forceOverwrites) {
            $builder->addArg('--force-overwrites');
        }

        $process = new Process($builder->buildForDownload(
            $formatCode,
            $outputPath,
            $outputFormat,
            $this->resolveLineBufferedProgress($progressNewline),
            $concurrentFragments ?? $this->bootstrap->getConcurrentFragments(),
            $this->resolveProgressDelta($progressDelta),
        ));
        $process->setTimeout(null);
        $process->setEnv(YtDlpClient::buildProcessEnv());

        return $process;
    }

    public function finalizeProcessResult(Process $process, ?string $expectedFile, bool $emitLogs): DownloadResult
    {
        if ($process->isSuccessful()) {
            if (is_string($expectedFile) && $expectedFile !== '' && file_exists($expectedFile) && $emitLogs) {
                $size = filesize($expectedFile);
                $this->logOutputPath($expectedFile, $size !== false ? $size : null);
                $this->logOutputDirectory($expectedFile);
            }
            if ($emitLogs) {
                $this->logger->info('🎉 Ура! Загрузка завершена!');
            }

            return new DownloadResult('completed');
        }

        $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'download_failed');
        $removedArtifacts = $this->cleanupFailedDownloadArtifacts($expectedFile);
        if ($emitLogs) {
            $this->logger->error('😭 Ой, во время загрузки или получения метаданных произошла ошибка.');
            if ($removedArtifacts > 0) {
                $this->logger->warning('🧹 Удалены временные файлы загрузки: ' . $removedArtifacts);
            }
            $this->logger->error('❌ Подробности: ' . $detail);
        }

        return new DownloadResult('failed', $detail);
    }

    private function downloadFastStreams(
        FastStreamFormatPair $pair,
        string $expectedFile,
        ?string $proxy,
        bool $insecure,
        bool $forceOverwrites,
        string $sourceUrl,
        ?int $concurrentFragments = null,
        ?string $progressDelta = null,
    ): DownloadResult {
        $tempDir = $this->createTemporaryDirectory();
        if ($tempDir === null) {
            return new DownloadResult('failed', 'tempdir_failed');
        }

        $filesystem = new Filesystem();
        $videoPath = $tempDir . '/video.' . $pair->video->extension;
        $audioPath = $tempDir . '/audio.' . $pair->audio->extension;

        try {
            $filesystem->mkdir(dirname($expectedFile));
            $this->logger->info(sprintf(
                '🚀 Начинаю быструю загрузку потоков: video=%s, audio=%s',
                $pair->video->formatId,
                $pair->audio->formatId,
            ));

            [$videoProcess, $audioProcess] = $this->downloadFastStreamFilesWithRetries(
                $pair,
                $videoPath,
                $audioPath,
                $proxy,
                $insecure,
                $sourceUrl,
                $concurrentFragments,
                $progressDelta,
            );

            if (!$videoProcess->isSuccessful() || !$audioProcess->isSuccessful()) {
                $detail = $this->formatFastStreamFailureDetail($videoProcess, $audioProcess);
                $removedArtifacts = $this->cleanupFailedDownloadArtifacts($expectedFile);
                $this->logger->error('😭 Ой, во время быстрой загрузки потоков произошла ошибка.');
                if ($removedArtifacts > 0) {
                    $this->logger->warning('🧹 Удалены временные файлы загрузки: ' . $removedArtifacts);
                }
                $this->logger->error('❌ Подробности: ' . $detail);

                return new DownloadResult('failed', $detail);
            }

            $this->logger->info('🔗 Объединяю видео и аудио через ffmpeg...');
            $mergeProcess = $this->runFfmpegMerge($videoPath, $audioPath, $expectedFile, $forceOverwrites);
            if (!$mergeProcess->isSuccessful()) {
                $detail = $this->ytDlpClient->getProcessErrorDetail($mergeProcess, 'ffmpeg_merge_failed');
                $removedArtifacts = $this->cleanupFailedDownloadArtifacts($expectedFile);
                $this->logger->error('😭 Не удалось объединить видео и аудио через ffmpeg.');
                if ($removedArtifacts > 0) {
                    $this->logger->warning('🧹 Удалены временные файлы загрузки: ' . $removedArtifacts);
                }
                $this->logger->error('❌ Подробности: ' . $detail);

                return new DownloadResult('failed', $detail);
            }

            $size = filesize($expectedFile);
            $this->logOutputPath($expectedFile, $size !== false ? $size : null);
            $this->logOutputDirectory($expectedFile);
            $this->logger->info('🎉 Ура! Загрузка завершена!');

            return new DownloadResult('completed');
        } finally {
            $filesystem->remove($tempDir);
        }
    }

    private function createRawStreamProcess(
        string $formatId,
        string $outputPath,
        ?string $proxy,
        bool $insecure,
        string $sourceUrl,
        ?int $concurrentFragments = null,
        ?string $progressDelta = null,
    ): Process {
        $builder = new YtDlpCommandBuilder($sourceUrl);
        $builder->setProxy($proxy)->setInsecure($insecure);

        $process = new Process($builder->buildForRawStreamDownload(
            $formatId,
            $outputPath,
            true,
            $concurrentFragments ?? $this->bootstrap->getConcurrentFragments(),
            $this->resolveProgressDelta($progressDelta),
        ));
        $process->setTimeout(null);
        $process->setEnv(YtDlpClient::buildProcessEnv());

        return $process;
    }

    /**
     * @return array{0:Process, 1:Process}
     */
    private function downloadFastStreamFilesWithRetries(
        FastStreamFormatPair $pair,
        string $videoPath,
        string $audioPath,
        ?string $proxy,
        bool $insecure,
        string $sourceUrl,
        ?int $concurrentFragments = null,
        ?string $progressDelta = null,
    ): array {
        $videoProcess = null;
        $audioProcess = null;
        $retryVideo = true;
        $retryAudio = true;

        for ($attempt = 1; $attempt <= self::FAST_STREAM_MAX_ATTEMPTS; ++$attempt) {
            $running = [];

            if ($retryVideo) {
                $videoProcess = $this->createRawStreamProcess(
                    $pair->video->formatId,
                    $videoPath,
                    $proxy,
                    $insecure,
                    $sourceUrl,
                    $concurrentFragments,
                    $progressDelta,
                );
                $running['video'] = $videoProcess;
            }

            if ($retryAudio) {
                $audioProcess = $this->createRawStreamProcess(
                    $pair->audio->formatId,
                    $audioPath,
                    $proxy,
                    $insecure,
                    $sourceUrl,
                    $concurrentFragments,
                    $progressDelta,
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

        return $this->isRetryableHttp403Failure($process);
    }

    private function isRetryableHttp403Failure(Process $process): bool
    {
        $detail = $this->ytDlpClient->getProcessErrorDetail($process, '');

        return str_contains($detail, 'HTTP Error 403') || str_contains($detail, 'HTTP 403');
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

        $this->logger->warning(sprintf(
            '🔁 HTTP 403 на fast-потоке %s; повторяю попытку %d/%d с докачкой.',
            implode('+', $labels),
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
        $progress = new YtDlpProgressRenderer($this->logger, array_keys($processes));

        while ($this->hasRunningProcess($processes)) {
            foreach ($processes as $label => $process) {
                $this->flushProcessOutput($label, $process, $progress);
            }
            usleep(100000);
        }

        foreach ($processes as $label => $process) {
            $this->flushProcessOutput($label, $process, $progress);
        }
        $progress->finish();
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

    private function flushProcessOutput(string $label, Process $process, YtDlpProgressRenderer $progress): void
    {
        $progress->consume($label, $process->getIncrementalOutput());
        $progress->consume($label, $process->getIncrementalErrorOutput());
    }

    private function logPrefixedProcessChunk(string $label, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $normalized = str_replace("\r", "\n", $chunk);
        $lines = preg_split('/\n+/', $normalized);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $this->logger->info('[' . $label . '] ' . $line);
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

        return $details !== [] ? implode("\n", $details) : 'fast_stream_download_failed';
    }

    private function runFfmpegMerge(string $videoPath, string $audioPath, string $expectedFile, bool $forceOverwrites): Process
    {
        $process = new Process([
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
        $process->setTimeout(null);
        $process->setEnv(YtDlpClient::buildProcessEnv());
        $process->run();

        return $process;
    }

    private function cleanupFailedDownloadArtifacts(?string $expectedFile): int
    {
        if (!is_string($expectedFile) || $expectedFile === '') {
            return 0;
        }

        $removedCount = 0;
        $candidates = [
            $expectedFile,
            $expectedFile . '.part',
            $expectedFile . '.ytdl',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && @unlink($candidate)) {
                ++$removedCount;
            }
        }

        return $removedCount;
    }

    private function createTemporaryDirectory(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'ytd_fast_');
        if ($path === false) {
            return null;
        }

        if (file_exists($path)) {
            unlink($path);
        }

        return mkdir($path, 0777, true) ? $path : null;
    }

    /**
     * @param array<mixed> $metadata
     */
    private function buildFallbackExpectedOutputPath(array $metadata, string $basePath, string $outputFormat): string
    {
        $title = $metadata['title'] ?? $metadata['fulltitle'] ?? $metadata['id'] ?? 'video';
        $filename = is_string($title) && trim($title) !== ''
            ? trim($title)
            : 'video';

        return $basePath . '/' . $filename . '.' . $outputFormat;
    }

    private function replaceOutputExtension(string $path, string $extension): string
    {
        $info = pathinfo($path);
        $directory = isset($info['dirname']) && $info['dirname'] !== '.'
            ? $info['dirname'] . '/'
            : '';
        $filename = is_string($info['filename'] ?? null) && $info['filename'] !== ''
            ? $info['filename']
            : basename($path);

        return $directory . $filename . '.' . $extension;
    }

    private function resolveLineBufferedProgress(?bool $override): bool
    {
        return $override ?? $this->bootstrap->shouldUseProgressNewline();
    }

    private function resolveProgressDelta(?string $override): string
    {
        return is_string($override) && $override !== ''
            ? $override
            : $this->bootstrap->getProgressDelta();
    }

    private function logOutputPath(string $expectedFile, ?int $sizeBytes = null): void
    {
        if ($sizeBytes === null) {
            $this->logger->info('📄 Файл: ' . $expectedFile);

            return;
        }

        $this->logger->info('📄 Файл: ' . $expectedFile);
        $this->logger->info('📦 Размер: ' . $this->formatSize($sizeBytes));
    }

    private function logOutputDirectory(string $expectedFile): void
    {
        $this->logger->info('📂 Каталог: ' . dirname($expectedFile));
    }

    private function logExistingOutputTarget(string $expectedFile): void
    {
        $size = filesize($expectedFile);
        $this->logOutputPath($expectedFile, $size !== false ? $size : null);
        $this->logOutputDirectory($expectedFile);
    }
}
