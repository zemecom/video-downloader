<?php

declare(strict_types=1);

namespace YtdPhp\Download;

use YtdPhp\Runtime\RuntimeBootstrap;

final readonly class ExpectedOutputResolver
{
    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
    ) {}

    public function resolveFromInfoJson(
        string $formatCode,
        string $outputTemplate,
        DownloadOptions $options,
        string $infoJsonPath,
        bool $sanitize = true,
    ): ?string {
        $expectedFile = $this->ytDlpClient->getExpectedFilename(
            videoUrl: null,
            formatCode: $formatCode,
            outputPath: $outputTemplate,
            proxy: $options->proxy,
            insecure: $options->insecure,
            infoJsonPath: $infoJsonPath,
            outputFormat: $options->outputFormat,
        );

        if ($sanitize && \is_string($expectedFile) && $expectedFile !== '') {
            return $this->bootstrap->sanitizeOutputFilename($expectedFile);
        }

        return $expectedFile;
    }

    /**
     * @param array<mixed> $metadata
     */
    public function resolveFastExpectedFile(
        string $formatCode,
        string $outputTemplate,
        DownloadOptions $options,
        string $infoJsonPath,
        array $metadata,
        string $basePath,
    ): string {
        $expectedFile = $this->resolveFromInfoJson(
            formatCode: $formatCode,
            outputTemplate: $outputTemplate,
            options: $options,
            infoJsonPath: $infoJsonPath,
        ) ?? $this->buildFallbackExpectedOutputPath($metadata, $basePath, $options->outputFormat);

        return $this->replaceOutputExtension($this->bootstrap->sanitizeOutputFilename($expectedFile), $options->outputFormat);
    }

    /**
     * @param array<mixed> $metadata
     */
    private function buildFallbackExpectedOutputPath(array $metadata, string $basePath, string $outputFormat): string
    {
        $title = $metadata['title'] ?? $metadata['fulltitle'] ?? $metadata['id'] ?? 'video';
        $filename = \is_string($title) && \trim($title) !== ''
            ? \trim($title)
            : 'video';

        return $basePath . '/' . $filename . '.' . $outputFormat;
    }

    private function replaceOutputExtension(string $path, string $extension): string
    {
        $info = \pathinfo($path);
        $directory = isset($info['dirname']) && $info['dirname'] !== '.'
            ? $info['dirname'] . '/'
            : '';
        $filename = \is_string($info['filename'] ?? null) && $info['filename'] !== ''
            ? $info['filename']
            : \basename($path);

        return $directory . $filename . '.' . $extension;
    }
}
