<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use function count;
use function is_array;
use function is_float;
use function is_int;
use function str_contains;
use function is_string;
use function strtolower;
use function str_starts_with;

final class AutomaticFormatResolver
{
    /**
     * @param array<mixed> $metadata
     */
    public function resolve(string $formatCode, array $metadata, bool $preferNonAv1 = false, string $outputFormat = 'mkv'): string
    {
        if ($formatCode === 'bestaudio') {
            return $formatCode;
        }

        $requireBrowserSafeMp4 = $outputFormat === 'mp4';
        $maxHeight = $this->qualityPresetMaxHeight($formatCode);
        if ($maxHeight !== null) {
            $recommendedFormatId = $this->resolvePreferredRequestedDownloadFormatId(
                $metadata['requested_downloads'] ?? null,
                $metadata['formats'] ?? null,
                $preferNonAv1,
                $maxHeight,
                $requireBrowserSafeMp4,
            );
            if ($recommendedFormatId !== null) {
                return $recommendedFormatId;
            }

            $fallbackFormatId = $this->resolveBestMuxedFormatId(
                $metadata['formats'] ?? null,
                $preferNonAv1,
                $maxHeight,
                $requireBrowserSafeMp4,
            );

            return $fallbackFormatId ?? $formatCode;
        }

        if ($formatCode !== 'best' || !$this->shouldPreferRecommendedDownload($metadata)) {
            return $formatCode;
        }

        $requestedDownloads = $metadata['requested_downloads'] ?? null;
        $recommendedFormatId = $this->resolvePreferredRequestedDownloadFormatId(
            $requestedDownloads,
            $metadata['formats'] ?? null,
            $preferNonAv1,
            null,
            $requireBrowserSafeMp4,
        );
        if ($recommendedFormatId !== null) {
            return $recommendedFormatId;
        }

        $fallbackFormatId = $this->resolveBestMuxedFormatId(
            $metadata['formats'] ?? null,
            $preferNonAv1,
            null,
            $requireBrowserSafeMp4,
        );

        return $fallbackFormatId ?? $formatCode;
    }

    private function qualityPresetMaxHeight(string $formatCode): ?int
    {
        return match ($formatCode) {
            'medium' => 720,
            'low' => 480,
            default => null,
        };
    }

    /**
     * @param array<mixed> $metadata
     */
    private function shouldPreferRecommendedDownload(array $metadata): bool
    {
        if (($metadata['is_live'] ?? false) === true) {
            return true;
        }

        if (($metadata['was_live'] ?? false) === true) {
            return true;
        }

        $liveStatus = $metadata['live_status'] ?? null;

        return $liveStatus === 'is_live' || $liveStatus === 'post_live' || $liveStatus === 'was_live';
    }

    private function resolveBestMuxedFormatId(
        mixed $formats,
        bool $preferNonAv1 = false,
        ?int $maxHeight = null,
        bool $requireBrowserSafeMp4 = false,
    ): ?string {
        if (!is_array($formats)) {
            return null;
        }

        $bestHlsFormatId = null;
        $bestHlsScore = -1;
        $bestMuxedFormatId = null;
        $bestMuxedScore = -1;

        foreach ($formats as $format) {
            if (!is_array($format) || !$this->isMuxedFormat($format)) {
                continue;
            }

            if (($preferNonAv1 && $this->isAv1Format($format))
                || !$this->matchesHeightCap($format, $maxHeight)
                || ($requireBrowserSafeMp4 && !$this->isBrowserSafeMp4Format($format))) {
                continue;
            }

            $formatId = $format['format_id'] ?? null;
            if (!is_string($formatId) || $formatId === '') {
                continue;
            }

            $score = $this->buildFormatScore($format);
            if ($score > $bestMuxedScore) {
                $bestMuxedScore = $score;
                $bestMuxedFormatId = $formatId;
            }

            if ($this->isHlsProtocol($format['protocol'] ?? null) && $score > $bestHlsScore) {
                $bestHlsScore = $score;
                $bestHlsFormatId = $formatId;
            }
        }

        return $bestHlsFormatId ?? $bestMuxedFormatId;
    }

    private function resolvePreferredRequestedDownloadFormatId(
        mixed $requestedDownloads,
        mixed $formats,
        bool $preferNonAv1 = false,
        ?int $maxHeight = null,
        bool $requireBrowserSafeMp4 = false,
    ): ?string {
        if (!is_array($requestedDownloads) || count($requestedDownloads) !== 1 || !is_array($requestedDownloads[0])) {
            return null;
        }

        $recommendedDownload = $requestedDownloads[0];
        $recommendedFormatId = $recommendedDownload['format_id'] ?? null;
        if (!is_string($recommendedFormatId) || $recommendedFormatId === '') {
            return null;
        }

        $knownFormat = $this->findFormatById($recommendedFormatId, $formats);
        if ($knownFormat !== null) {
            if (($preferNonAv1 && $this->isAv1Format($knownFormat))
                || !$this->matchesHeightCap($knownFormat, $maxHeight)
                || ($requireBrowserSafeMp4 && !$this->isBrowserSafeMp4Format($knownFormat))) {
                return null;
            }

            return $recommendedFormatId;
        }

        if (($preferNonAv1 && $this->hasKnownVideoCodec($recommendedDownload) && $this->isAv1Format($recommendedDownload))
            || !$this->matchesHeightCap($recommendedDownload, $maxHeight)
            || ($requireBrowserSafeMp4 && !$this->isBrowserSafeMp4Format($recommendedDownload))) {
            return null;
        }

        return $recommendedFormatId;
    }

    /**
     * @param array<mixed> $format
     */
    private function isMuxedFormat(array $format): bool
    {
        $audioCodec = $format['acodec'] ?? null;
        $videoCodec = $format['vcodec'] ?? null;

        return is_string($audioCodec)
            && $audioCodec !== ''
            && $audioCodec !== 'none'
            && is_string($videoCodec)
            && $videoCodec !== ''
            && $videoCodec !== 'none';
    }

    /**
     * @param array<mixed> $format
     */
    private function hasKnownVideoCodec(array $format): bool
    {
        $videoCodec = $format['vcodec'] ?? null;

        return is_string($videoCodec)
            && $videoCodec !== ''
            && $videoCodec !== 'none';
    }

    /**
     * @param array<mixed> $format
     */
    private function isAv1Format(array $format): bool
    {
        $videoCodec = $format['vcodec'] ?? null;

        return is_string($videoCodec)
            && $videoCodec !== ''
            && str_starts_with(strtolower($videoCodec), 'av01');
    }

    /**
     * @param array<mixed> $format
     */
    private function isBrowserSafeMp4Format(array $format): bool
    {
        $extension = strtolower((string) ($format['ext'] ?? ''));

        return ($extension === '' || $extension === 'mp4' || $extension === 'm4v')
            && $this->isBrowserSafeVideoCodec($format)
            && $this->isBrowserSafeAudioCodec($format);
    }

    /**
     * @param array<mixed> $format
     */
    private function isBrowserSafeVideoCodec(array $format): bool
    {
        $videoCodec = strtolower((string) ($format['vcodec'] ?? ''));

        return str_starts_with($videoCodec, 'avc1')
            || str_starts_with($videoCodec, 'h264')
            || str_starts_with($videoCodec, 'h.264');
    }

    /**
     * @param array<mixed> $format
     */
    private function isBrowserSafeAudioCodec(array $format): bool
    {
        $audioCodec = strtolower((string) ($format['acodec'] ?? ''));

        return str_starts_with($audioCodec, 'mp4a')
            || str_starts_with($audioCodec, 'aac');
    }

    /**
     * @param array<mixed> $formats
     * @return array<mixed>|null
     */
    private function findFormatById(string $formatId, mixed $formats): ?array
    {
        if (!is_array($formats)) {
            return null;
        }

        foreach ($formats as $format) {
            if (!is_array($format)) {
                continue;
            }

            if (($format['format_id'] ?? null) === $formatId) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $format
     */
    private function buildFormatScore(array $format): int
    {
        $height = $this->normalizeNumber($format['height'] ?? null);
        $fps = $this->normalizeNumber($format['fps'] ?? null);
        $tbr = $this->normalizeNumber($format['tbr'] ?? null);

        return ($height * 1_000_000) + ($fps * 1_000) + $tbr;
    }

    private function normalizeNumber(mixed $value): int
    {
        return is_int($value) || is_float($value)
            ? (int) round($value)
            : 0;
    }

    private function isHlsProtocol(mixed $protocol): bool
    {
        return is_string($protocol) && str_contains($protocol, 'm3u8');
    }

    /**
     * @param array<mixed> $format
     */
    private function matchesHeightCap(array $format, ?int $maxHeight): bool
    {
        if ($maxHeight === null) {
            return true;
        }

        $height = $this->normalizeNumber($format['height'] ?? null);

        return $height > 0 && $height <= $maxHeight;
    }
}
