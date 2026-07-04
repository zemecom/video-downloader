<?php

declare(strict_types=1);

namespace YtdPhp\Download;

use YtdPhp\Download\YtDlp\YtDlpCommandBuilder;
use YtdPhp\Download\YtDlp\YtDlpGateway;
use YtdPhp\Download\Format\AutomaticFormatResolver;
use YtdPhp\Download\Format\FastStreamFormatResolver;
use YtdPhp\Download\Metadata\DownloadMetadata;
use YtdPhp\Download\Metadata\DownloadMetadataService;
use YtdPhp\Download\Process\DownloadProcessRunner;
use YtdPhp\Download\Process\DownloadOutputFormatter;
use YtdPhp\Download\Process\DownloadArtifactCleaner;
use YtdPhp\Download\Process\DownloadTemporaryStorage;
use YtdPhp\Download\Process\ExpectedOutputResolver;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Shared\InputPrompter;

final readonly class DownloaderService
{
    private DownloadTemporaryStorage $temporaryStorage;
    private DownloadOutputFormatter $outputFormatter;
    private DownloadArtifactCleaner $artifactCleaner;
    private DownloadProcessRunner $processRunner;
    private DownloadMetadataService $metadataService;
    private ExpectedOutputResolver $expectedOutputResolver;
    private FastStreamDownloadService $fastStreamDownloadService;

    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
        private ConsoleLogger $logger,
        private InputPrompter $prompter,
        private AutomaticFormatResolver $automaticFormatResolver = new AutomaticFormatResolver(),
        private FastStreamFormatResolver $fastStreamFormatResolver = new FastStreamFormatResolver(),
        ?DownloadTemporaryStorage $temporaryStorage = null,
        ?DownloadOutputFormatter $outputFormatter = null,
        ?DownloadArtifactCleaner $artifactCleaner = null,
        ?DownloadProcessRunner $processRunner = null,
        ?DownloadMetadataService $metadataService = null,
        ?ExpectedOutputResolver $expectedOutputResolver = null,
        ?FastStreamDownloadService $fastStreamDownloadService = null,
    ) {
        $this->temporaryStorage = $temporaryStorage ?? new DownloadTemporaryStorage();
        $this->outputFormatter = $outputFormatter ?? new DownloadOutputFormatter($this->logger);
        $this->artifactCleaner = $artifactCleaner ?? new DownloadArtifactCleaner();
        $this->processRunner = $processRunner ?? new DownloadProcessRunner($this->ytDlpClient, $this->logger);
        $this->metadataService = $metadataService ?? new DownloadMetadataService($this->ytDlpClient, $this->logger, $this->temporaryStorage);
        $this->expectedOutputResolver = $expectedOutputResolver ?? new ExpectedOutputResolver($this->ytDlpClient, $this->bootstrap);
        $this->fastStreamDownloadService = $fastStreamDownloadService ?? new FastStreamDownloadService(
            $this->ytDlpClient,
            $this->bootstrap,
            $this->logger,
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
        DownloadOptions $options,
    ): DownloadResult {
        return $this->withElapsedRuntime(
            fn(): DownloadResult => $this->downloadVideoInternal(
                videoUrl: $videoUrl,
                formatCode: $formatCode,
                options: $options,
            ),
            $options->emitElapsedRuntime,
        );
    }

    private function downloadVideoInternal(
        string $videoUrl,
        string $formatCode,
        DownloadOptions $options,
    ): DownloadResult {
        $basePath = $this->bootstrap->getDownloadBasePath($videoUrl, $options->downloadDir);
        $outputTemplate = $basePath . '/%(title)s.%(ext)s';

        $metadataResult = $this->metadataService->fetch(videoUrl: $videoUrl, proxy: $options->proxy, insecure: $options->insecure);
        if ($metadataResult->failure instanceof DownloadResult) {
            return $metadataResult->failure;
        }
        $metadata = $metadataResult->metadata;
        if (!$metadata instanceof DownloadMetadata) {
            return new DownloadResult('failed', 'metadata_failed');
        }

        $resolvedFormatCode = $this->resolveRequestedFormatCode(
            formatCode: $formatCode,
            metadata: $metadata->payload,
            sourceUrl: $videoUrl,
            outputFormat: $options->outputFormat,
        );

        try {
            $this->logger->info('🔍 Проверяю наличие файла...');
            $expectedFile = $this->expectedOutputResolver->resolveFromInfoJson(
                formatCode: $resolvedFormatCode,
                outputTemplate: $outputTemplate,
                options: $options,
                infoJsonPath: $metadata->infoJsonPath,
            );

            if ($options->dryRun) {
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
                infoJsonPath: $metadata->infoJsonPath,
                outputTemplate: $downloadTarget,
                formatCode: $resolvedFormatCode,
                options: $options->with(forceOverwrites: $forceOverwrites),
                expectedFile: $expectedFile,
                sourceUrl: $videoUrl,
            );
        } finally {
            $this->temporaryStorage->removeFileIfExists($metadata->infoJsonPath);
        }
    }

    public function downloadVideoFast(
        string $videoUrl,
        string $qualityPreset,
        DownloadOptions $options,
    ): DownloadResult {
        return $this->withElapsedRuntime(
            fn(): DownloadResult => $this->downloadVideoFastInternal(
                videoUrl: $videoUrl,
                qualityPreset: $qualityPreset,
                options: $options,
            ),
            $options->emitElapsedRuntime,
        );
    }

    private function downloadVideoFastInternal(
        string $videoUrl,
        string $qualityPreset,
        DownloadOptions $options,
    ): DownloadResult {
        $basePath = $this->bootstrap->getDownloadBasePath($videoUrl, $options->downloadDir);
        $outputTemplate = $basePath . '/%(title)s.%(ext)s';

        $metadataResult = $this->metadataService->fetch(videoUrl: $videoUrl, proxy: $options->proxy, insecure: $options->insecure);
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
                $options->outputFormat,
            );
            if (!$pair instanceof \YtdPhp\Download\Format\FastStreamFormatPair) {
                $this->logger->warning('⚠️ Не удалось подобрать отдельные video/audio потоки для `--fast`; использую обычную загрузку.');

                return $this->downloadVideoInternal(
                    videoUrl: $videoUrl,
                    formatCode: $qualityPreset,
                    options: $options,
                );
            }

            $resolvedFormatCode = $this->resolveRequestedFormatCode(
                formatCode: $qualityPreset,
                metadata: $metadata->payload,
                sourceUrl: $videoUrl,
                outputFormat: $options->outputFormat,
            );
            $expectedFile = $this->expectedOutputResolver->resolveFastExpectedFile(
                formatCode: $resolvedFormatCode,
                outputTemplate: $outputTemplate,
                options: $options,
                infoJsonPath: $metadata->infoJsonPath,
                metadata: $metadata->payload,
                basePath: $basePath,
            );

            if ($options->dryRun) {
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
                $videoUrl,
                $options->with(forceOverwrites: $forceOverwrites),
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
        string $formatCode,
        DownloadOptions $options,
        ?string $expectedFile = null,
        ?string $sourceUrl = null,
    ): DownloadResult {
        $expectedFile ??= $this->expectedOutputResolver->resolveFromInfoJson(
            $formatCode,
            $outputTemplate,
            $options,
            $infoJsonPath,
            false,
        );

        if (\is_string($expectedFile) && $expectedFile !== '' && \file_exists($expectedFile) && !$options->forceOverwrites) {
            if ($options->emitLogs) {
                $this->logger->warning('⚠️ Файл уже существует: ' . $expectedFile);
                $this->outputFormatter->logExistingOutputTarget($expectedFile);
            }

            return new DownloadResult('skipped', 'file_exists');
        }

        $builder = new YtDlpCommandBuilder($sourceUrl);
        $builder->setProxy($options->proxy)->setInsecure($options->insecure)->loadInfoJson($infoJsonPath);
        if ($options->forceOverwrites) {
            $builder->addArg('--force-overwrites');
        }

        $command = $builder->buildForDownload(
            $formatCode,
            $outputTemplate,
            $options->outputFormat,
            $this->resolveLineBufferedProgress($options->progressNewline),
            $options->concurrentFragments ?? $this->bootstrap->getConcurrentFragments(),
            $this->resolveProgressDelta($options->progressDelta),
        );
        if ($options->emitLogs) {
            $this->logger->info('🚀 Начинаю загрузку...');
        }
        $process = $this->processRunner->runWithRetries($command, $options->emitLogs);

        return $this->finalizeProcessResult($process, $expectedFile, $options->emitLogs);
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
        string $formatCode,
        DownloadOptions $options,
        ?string $sourceUrl = null,
    ): Process {
        $builder = new YtDlpCommandBuilder($sourceUrl);
        $builder->setProxy($options->proxy)->setInsecure($options->insecure)->loadInfoJson($infoJsonPath);
        if ($options->forceOverwrites) {
            $builder->addArg('--force-overwrites');
        }

        return $this->processRunner->createProcess($builder->buildForDownload(
            $formatCode,
            $outputPath,
            $options->outputFormat,
            $this->resolveLineBufferedProgress($options->progressNewline),
            $options->concurrentFragments ?? $this->bootstrap->getConcurrentFragments(),
            $this->resolveProgressDelta($options->progressDelta),
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
