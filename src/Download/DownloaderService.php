<?php

declare(strict_types=1);

namespace YtdPhp\Download;

use Symfony\Component\Process\Process;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Shared\InputPrompter;

final readonly class DownloaderService
{
    private YtDlpGateway $ytDlpClient;
    private RuntimeBootstrap $bootstrap;
    private ConsoleLogger $logger;
    private InputPrompter $prompter;
    private AutomaticFormatResolver $automaticFormatResolver;
    private FastStreamFormatResolver $fastStreamFormatResolver;
    private DownloadTemporaryStorage $temporaryStorage;
    private DownloadOutputFormatter $outputFormatter;
    private DownloadArtifactCleaner $artifactCleaner;
    private DownloadProcessRunner $processRunner;
    private DownloadMetadataService $metadataService;
    private ExpectedOutputResolver $expectedOutputResolver;
    private FastStreamDownloadService $fastStreamDownloadService;

    public function __construct(
        YtDlpGateway $ytDlpClient,
        RuntimeBootstrap $bootstrap,
        ConsoleLogger $logger,
        InputPrompter $prompter,
        AutomaticFormatResolver $automaticFormatResolver = new AutomaticFormatResolver(),
        FastStreamFormatResolver $fastStreamFormatResolver = new FastStreamFormatResolver(),
        ?DownloadTemporaryStorage $temporaryStorage = null,
        ?DownloadOutputFormatter $outputFormatter = null,
        ?DownloadArtifactCleaner $artifactCleaner = null,
        ?DownloadProcessRunner $processRunner = null,
        ?DownloadMetadataService $metadataService = null,
        ?ExpectedOutputResolver $expectedOutputResolver = null,
        ?FastStreamDownloadService $fastStreamDownloadService = null,
    ) {
        $this->ytDlpClient = $ytDlpClient;
        $this->bootstrap = $bootstrap;
        $this->logger = $logger;
        $this->prompter = $prompter;
        $this->automaticFormatResolver = $automaticFormatResolver;
        $this->fastStreamFormatResolver = $fastStreamFormatResolver;
        $this->temporaryStorage = $temporaryStorage ?? new DownloadTemporaryStorage();
        $this->outputFormatter = $outputFormatter ?? new DownloadOutputFormatter($logger);
        $this->artifactCleaner = $artifactCleaner ?? new DownloadArtifactCleaner();
        $this->processRunner = $processRunner ?? new DownloadProcessRunner($ytDlpClient, $logger);
        $this->metadataService = $metadataService ?? new DownloadMetadataService($ytDlpClient, $logger, $this->temporaryStorage);
        $this->expectedOutputResolver = $expectedOutputResolver ?? new ExpectedOutputResolver($ytDlpClient, $bootstrap);
        $this->fastStreamDownloadService = $fastStreamDownloadService ?? new FastStreamDownloadService(
            $ytDlpClient,
            $bootstrap,
            $logger,
            $this->processRunner,
            $this->temporaryStorage,
            $this->artifactCleaner,
            $this->outputFormatter,
        );
    }

    public function formatSize(int $sizeBytes): string
    {
        return $this->outputFormatter->formatSize($sizeBytes);
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

        $metadataResult = $this->metadataService->fetch($videoUrl, $proxy, $insecure);
        if ($metadataResult->failure instanceof DownloadResult) {
            return $metadataResult->failure;
        }
        $metadata = $metadataResult->metadata;
        if (!$metadata instanceof DownloadMetadata) {
            return new DownloadResult('failed', 'metadata_failed');
        }

        $resolvedFormatCode = $this->resolveRequestedFormatCode($formatCode, $metadata->payload, $videoUrl, $outputFormat);

        try {
            $this->logger->info('🔍 Проверяю наличие файла...');
            $expectedFile = $this->expectedOutputResolver->resolveFromInfoJson(
                $resolvedFormatCode,
                $outputTemplate,
                $proxy,
                $insecure,
                $metadata->infoJsonPath,
                $outputFormat,
            );

            if ($dryRun) {
                $this->logger->info('🧪 Режим dry-run: показываю результат preflight без загрузки.');
                if (\is_string($expectedFile) && $expectedFile !== '') {
                    $this->outputFormatter->logOutputPath($expectedFile);
                }
                if (\is_string($expectedFile) && \file_exists($expectedFile)) {
                    $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                    $this->logger->info('↪️ В обычном режиме был бы показан вопрос о перезаписи.');
                } else {
                    $this->logger->info('⬇️ Будет скачано в формате: ' . $resolvedFormatCode);
                }

                return new DownloadResult('completed', 'dry_run');
            }

            $forceOverwrites = false;
            if (\is_string($expectedFile) && $expectedFile !== '' && \file_exists($expectedFile)) {
                $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                $choice = strtolower(\trim($this->prompter->ask('🔄 Перезаписать? [y/N]: ')));
                if ($choice !== 'y') {
                    $this->outputFormatter->logExistingOutputTarget($expectedFile);
                    $this->logger->info('⏭️ Пропускаю загрузку по выбору пользователя.');

                    return new DownloadResult('skipped', 'user_declined_overwrite');
                }
                $forceOverwrites = true;
            }

            $downloadTarget = $expectedFile ?? $outputTemplate;

            return $this->downloadFromInfoJson(
                $metadata->infoJsonPath,
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
            $this->temporaryStorage->removeFileIfExists($metadata->infoJsonPath);
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

        $metadataResult = $this->metadataService->fetch($videoUrl, $proxy, $insecure);
        if ($metadataResult->failure instanceof DownloadResult) {
            return $metadataResult->failure;
        }
        $metadata = $metadataResult->metadata;
        if (!$metadata instanceof DownloadMetadata) {
            return new DownloadResult('failed', 'metadata_failed');
        }

        try {
            $pair = $this->fastStreamFormatResolver->resolve(
                $qualityPreset,
                $metadata->payload,
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

            $resolvedFormatCode = $this->resolveRequestedFormatCode($qualityPreset, $metadata->payload, $videoUrl, $outputFormat);
            $expectedFile = $this->expectedOutputResolver->resolveFastExpectedFile(
                $resolvedFormatCode,
                $outputTemplate,
                $proxy,
                $insecure,
                $metadata->infoJsonPath,
                $outputFormat,
                $metadata->payload,
                $basePath,
            );

            if ($dryRun) {
                $this->logger->info('🧪 Режим dry-run: показываю результат preflight без загрузки.');
                $this->outputFormatter->logOutputPath($expectedFile);
                if (\file_exists($expectedFile)) {
                    $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                    $this->logger->info('↪️ В обычном режиме был бы показан вопрос о перезаписи.');
                } else {
                    $this->logger->info(\sprintf(
                        '⬇️ Быстрый режим скачает video=%s и audio=%s параллельно.',
                        $pair->video->formatId,
                        $pair->audio->formatId,
                    ));
                }

                return new DownloadResult('completed', 'dry_run');
            }

            $forceOverwrites = false;
            if (\file_exists($expectedFile)) {
                $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                $choice = strtolower(\trim($this->prompter->ask('🔄 Перезаписать? [y/N]: ')));
                if ($choice !== 'y') {
                    $this->outputFormatter->logExistingOutputTarget($expectedFile);
                    $this->logger->info('⏭️ Пропускаю загрузку по выбору пользователя.');

                    return new DownloadResult('skipped', 'user_declined_overwrite');
                }
                $forceOverwrites = true;
            }

            return $this->fastStreamDownloadService->download(
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
            $this->temporaryStorage->removeFileIfExists($metadata->infoJsonPath);
        }
    }

    /**
     * @param callable(): DownloadResult $operation
     */
    private function withElapsedRuntime(callable $operation, bool $emitElapsedRuntime): DownloadResult
    {
        $startedAt = \microtime(true);

        try {
            return $operation();
        } finally {
            if ($emitElapsedRuntime) {
                $this->logger->info('⏱️ Время работы: ' . $this->outputFormatter->formatElapsedRuntime(\microtime(true) - $startedAt));
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
        $expectedFile ??= $this->expectedOutputResolver->resolveFromInfoJson(
            $formatCode,
            $outputTemplate,
            $proxy,
            $insecure,
            $infoJsonPath,
            $outputFormat,
            false,
        );

        if (\is_string($expectedFile) && $expectedFile !== '' && \file_exists($expectedFile) && !$forceOverwrites) {
            if ($emitLogs) {
                $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                $this->outputFormatter->logExistingOutputTarget($expectedFile);
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
        $process = $this->processRunner->runWithRetries($command, $emitLogs);

        return $this->finalizeProcessResult($process, $expectedFile, $emitLogs);
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
            \is_string($sourceUrl) && $sourceUrl !== '' && $this->bootstrap->isYoutubeUrl($sourceUrl),
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

        return $this->processRunner->createProcess($builder->buildForDownload(
            $formatCode,
            $outputPath,
            $outputFormat,
            $this->resolveLineBufferedProgress($progressNewline),
            $concurrentFragments ?? $this->bootstrap->getConcurrentFragments(),
            $this->resolveProgressDelta($progressDelta),
        ));
    }

    public function finalizeProcessResult(Process $process, ?string $expectedFile, bool $emitLogs): DownloadResult
    {
        if ($process->isSuccessful()) {
            if (\is_string($expectedFile) && $expectedFile !== '' && \file_exists($expectedFile) && $emitLogs) {
                $size = \filesize($expectedFile);
                $this->outputFormatter->logOutputPath($expectedFile, $size !== false ? $size : null);
                $this->outputFormatter->logOutputDirectory($expectedFile);
            }
            if ($emitLogs) {
                $this->logger->info('🎉 Ура! Загрузка завершена!');
            }

            return new DownloadResult('completed');
        }

        $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'download_failed');
        $removedArtifacts = $this->artifactCleaner->cleanupFailedDownloadArtifacts($expectedFile);
        if ($emitLogs) {
            $this->logger->error('😭 Ой, во время загрузки или получения метаданных произошла ошибка.');
            if ($removedArtifacts > 0) {
                $this->logger->warning('🧹 Удалены временные файлы загрузки: ' . $removedArtifacts);
            }
            $this->logger->error('❌ Подробности: ' . $detail);
        }

        return new DownloadResult('failed', $detail);
    }

    private function resolveLineBufferedProgress(?bool $override): bool
    {
        return $override ?? $this->bootstrap->shouldUseProgressNewline();
    }

    private function resolveProgressDelta(?string $override): string
    {
        return \is_string($override) && $override !== ''
            ? $override
            : $this->bootstrap->getProgressDelta();
    }
}
