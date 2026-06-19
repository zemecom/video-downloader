<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Symfony\Component\Process\Process;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Dto\DownloadResult;

use function dirname;
use function file_exists;
use function file_put_contents;
use function filesize;
use function floor;
use function getenv;
use function is_dir;
use function is_file;
use function is_array;
use function json_decode;
use function log;
use function mkdir;
use function pathinfo;
use function preg_replace;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

final readonly class DownloaderService
{
    public function __construct(
        private YtDlpClient $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
        private ConsoleLogger $logger,
        private InputPrompter $prompter,
        private AutomaticFormatResolver $automaticFormatResolver = new AutomaticFormatResolver(),
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

        $resolvedFormatCode = $this->resolveRequestedFormatCode($formatCode, $metadata, $videoUrl);

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
        $process = $emitLogs
            ? $this->ytDlpClient->runLive($command, true)
            : $this->ytDlpClient->runCaptured($command);

        return $this->finalizeProcessResult($process, $expectedFile, $emitLogs);
    }

    /**
     * @param array<mixed> $metadata
     */
    public function resolveRequestedFormatCode(string $formatCode, array $metadata, ?string $sourceUrl = null): string
    {
        return $this->automaticFormatResolver->resolve(
            $formatCode,
            $metadata,
            is_string($sourceUrl) && $sourceUrl !== '' && $this->bootstrap->isYoutubeUrl($sourceUrl),
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

        $this->logger->info(sprintf('📄 Файл: %s (%s)', $expectedFile, $this->formatSize($sizeBytes)));
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
