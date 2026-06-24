<?php

declare(strict_types=1);

namespace YtdPhp\Playlist;

use Symfony\Component\Process\Process;
use YtdPhp\Download\DownloaderService;
use YtdPhp\Download\YtDlpGateway;
use YtdPhp\Download\YtDlpCommandBuilder;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Runtime\RuntimeOptions;

final readonly class PlaylistItemPreflightService
{
    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private RuntimeBootstrap $bootstrap,
        private DownloaderService $downloader,
        private PlaylistPayloadMapper $payloadMapper,
    ) {}

    public function buildPlaylistItemSize(PlaylistItem $item, PlaylistInfo $playlist, RuntimeOptions $options): PlaylistItem
    {
        $process = $this->probeItemProcess($item, $playlist, $options);
        if (!$process->isSuccessful()) {
            return $item;
        }
        $metadata = $this->payloadMapper->decode($process->getOutput());
        if ($metadata === null) {
            return $item;
        }

        [$filesize, $filesizeApprox] = $this->estimateItemSize($metadata);

        return new PlaylistItem(
            $item->playlistIndex,
            $item->title,
            $item->url,
            $item->status,
            $item->selectable,
            $filesize,
            $filesizeApprox,
            $filesize !== null || $filesizeApprox !== null,
        );
    }

    public function buildItemMetadata(
        PlaylistInfo $playlist,
        PlaylistItem $item,
        RuntimeOptions $options,
        string $targetDir,
    ): SelectedItemMetadata {
        $requestedFormatCode = $options->audioOnly ? 'bestaudio' : $options->qualityPreset;
        $usedDirectItemProbe = $this->canProbeItemDirectly($item);
        $process = $this->probeItemProcess($item, $playlist, $options);
        if (!$process->isSuccessful()) {
            $detail = $this->ytDlpClient->getProcessErrorDetail($process, 'playlist_item_metadata_failed');

            return $this->failedItemMetadata($item, $detail);
        }

        $metadata = $this->payloadMapper->decode($process->getOutput());
        if ($metadata === null) {
            return $this->failedItemMetadata($item, 'playlist_item_metadata_failed');
        }

        $metadata['playlist_index'] ??= $item->playlistIndex;
        $sourceUrl = $item->url !== '' ? $item->url : $playlist->sourceUrl;
        $resolvedFormatCode = $this->downloader->resolveRequestedFormatCode(
            $requestedFormatCode,
            $metadata,
            $sourceUrl,
            $options->outputFormat,
        );
        $tempJsonPath = $this->writePlaylistItemMetadataJson($metadata);
        if ($tempJsonPath === null) {
            return $this->failedItemMetadata($item, 'playlist_item_tempfile_failed');
        }

        $expectedPath = $this->resolveExpectedPath(
            $targetDir,
            $metadata,
            $item,
            $options,
            $resolvedFormatCode,
            $tempJsonPath,
            $usedDirectItemProbe,
        );
        $expectedPath = $this->bootstrap->sanitizeOutputFilename($expectedPath);

        [$filesize, $filesizeApprox] = $this->estimateItemSize($metadata);

        return new SelectedItemMetadata(
            $item,
            $tempJsonPath,
            $expectedPath,
            $resolvedFormatCode,
            \file_exists($expectedPath),
            $filesize,
            $filesizeApprox,
            $filesize !== null || $filesizeApprox !== null,
        );
    }

    /**
     * @param array<mixed> $metadata
     */
    private function resolveExpectedPath(
        string $targetDir,
        array $metadata,
        PlaylistItem $item,
        RuntimeOptions $options,
        string $resolvedFormatCode,
        string $tempJsonPath,
        bool $usedDirectItemProbe,
    ): string {
        if ($resolvedFormatCode === 'bestaudio') {
            return $this->ytDlpClient->getExpectedFilename(
                null,
                $resolvedFormatCode,
                $this->playlistOutputTemplate($targetDir),
                $options->currentProxy,
                $options->insecure,
                $tempJsonPath,
                $options->outputFormat,
            ) ?: $this->fallbackExpectedPath(
                $targetDir,
                $metadata,
                $item->playlistIndex,
                $options->outputFormat,
                $resolvedFormatCode,
            );
        }

        if ($usedDirectItemProbe) {
            return $this->fallbackExpectedPath(
                $targetDir,
                $metadata,
                $item->playlistIndex,
                $options->outputFormat,
                $resolvedFormatCode,
            );
        }

        return $this->ytDlpClient->getExpectedFilename(
            null,
            $resolvedFormatCode,
            $this->playlistOutputTemplate($targetDir),
            $options->currentProxy,
            $options->insecure,
            $tempJsonPath,
            $options->outputFormat,
        ) ?: $this->fallbackExpectedPath(
            $targetDir,
            $metadata,
            $item->playlistIndex,
            $options->outputFormat,
            $resolvedFormatCode,
        );
    }

    /**
     * @param array<mixed> $metadata
     */
    private function writePlaylistItemMetadataJson(array $metadata): ?string
    {
        $tempJson = \tempnam(\sys_get_temp_dir(), 'ytd_playlist_');
        if ($tempJson === false) {
            return null;
        }

        $tempJsonPath = $tempJson . '.json';
        @\unlink($tempJson);

        $encoded = \json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!\is_string($encoded) || \file_put_contents($tempJsonPath, $encoded) === false) {
            if (\file_exists($tempJsonPath)) {
                @\unlink($tempJsonPath);
            }

            return null;
        }

        return $tempJsonPath;
    }

    private function failedItemMetadata(PlaylistItem $item, string $errorMessage): SelectedItemMetadata
    {
        return new SelectedItemMetadata($item, '', '', 'best', false, null, null, false, $errorMessage);
    }

    private function probeItemProcess(PlaylistItem $item, PlaylistInfo $playlist, RuntimeOptions $options): Process
    {
        if ($this->canProbeItemDirectly($item)) {
            $builder = new YtDlpCommandBuilder($item->url);
            $builder->setProxy($options->currentProxy)->setInsecure($options->insecure);

            return $this->ytDlpClient->runCaptured($builder->buildForMetadata());
        }

        $builder = new YtDlpCommandBuilder($playlist->sourceUrl, true);
        $builder->setProxy($options->currentProxy)->setInsecure($options->insecure);

        return $this->ytDlpClient->runCaptured($builder->buildForPlaylistItemMetadata($item->playlistIndex));
    }

    private function canProbeItemDirectly(PlaylistItem $item): bool
    {
        return \str_starts_with($item->url, 'http://') || \str_starts_with($item->url, 'https://');
    }

    private function playlistOutputTemplate(string $targetDir): string
    {
        return $targetDir . '/%(playlist_index)03d - %(title)s [%(id)s].%(ext)s';
    }

    /**
     * @param array<mixed> $metadata
     * @return array{0:?int,1:?int}
     */
    private function estimateItemSize(array $metadata): array
    {
        $filesize = $this->payloadMapper->coerceInt($metadata['filesize'] ?? null);
        if ($filesize !== null) {
            return [$filesize, null];
        }

        $filesizeApprox = $this->payloadMapper->coerceInt($metadata['filesize_approx'] ?? null);
        if ($filesizeApprox !== null) {
            return [$filesizeApprox, $filesizeApprox];
        }

        $requestedFormats = $metadata['requested_formats'] ?? null;
        if (\is_array($requestedFormats) && $requestedFormats !== []) {
            $sizes = [];
            foreach ($requestedFormats as $format) {
                if (!\is_array($format)) {
                    return [null, null];
                }
                $formatSize = $this->payloadMapper->coerceInt($format['filesize'] ?? null) ?? $this->payloadMapper->coerceInt($format['filesize_approx'] ?? null);
                if ($formatSize === null) {
                    return [null, null];
                }
                $sizes[] = $formatSize;
            }

            return [array_sum($sizes), null];
        }

        return [null, null];
    }

    /**
     * @param array<mixed> $metadata
     */
    private function fallbackExpectedPath(
        string $targetDir,
        array $metadata,
        int $index,
        string $outputFormat,
        string $formatCode = 'best',
    ): string {
        $title = \trim((string) ($metadata['title'] ?? $metadata['fulltitle'] ?? 'video_' . $index));
        $safeTitle = $this->bootstrap->sanitizePathComponent($title, 'video_' . $index);
        $videoId = \trim((string) ($metadata['id'] ?? ''));
        $safeVideoId = $videoId !== ''
            ? $this->bootstrap->sanitizePathComponent($videoId, 'item_' . $index)
            : 'item_' . $index;
        $defaultExtension = $formatCode === 'bestaudio' ? 'opus' : $outputFormat;
        $ext = \trim((string) ($metadata['ext'] ?? $defaultExtension)) ?: $defaultExtension;

        return \sprintf('%s/%03d - %s [%s].%s', $targetDir, $index, $safeTitle, $safeVideoId, $ext);
    }
}
