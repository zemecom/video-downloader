<?php

declare(strict_types=1);

namespace YtdPhp\Download\Format;

final class AutomaticFormatResolver
{
    /**
     * @param array<mixed> $metadata
     */
    public function resolve(string $formatCode, array $metadata, bool $preferNonAv1 = false, string $outputFormat = 'mkv'): string
    {
        if (in_array($formatCode, ['best', 'bestaudio'], true)) {
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

        return $formatCode;
    }

    private function qualityPresetMaxHeight(string $formatCode): ?int
    {
        return match ($formatCode) {
            'medium' => 720,
            'low' => 480,
            default => null,
        };
    }

    private function resolveBestMuxedFormatId(
        mixed $formats,
        bool $preferNonAv1 = false,
        ?int $maxHeight = null,
        bool $requireBrowserSafeMp4 = false,
    ): ?string {
        if (!\is_array($formats)) {
            return null;
        }

        $bestHlsFormatId = null;
        $bestHlsScore = -1;
        $bestMuxedFormatId = null;
        $bestMuxedScore = -1;

        foreach ($formats as $format) {
            if (!\is_array($format)) {
                continue;
            }
            if (!$this->isMuxedFormat($format)) {
                continue;
            }
            if ($preferNonAv1 && $this->isAv1Format($format)) {
                continue;
            }
            if (!$this->matchesHeightCap($format, $maxHeight)) {
                continue;
            }
            if ($requireBrowserSafeMp4 && !$this->isBrowserSafeMp4Format($format)) {
                continue;
            }
            $formatId = $format['format_id'] ?? null;
            if (!\is_string($formatId)) {
                continue;
            }
            if ($formatId === '') {
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
        if (!\is_array($requestedDownloads) || \count($requestedDownloads) !== 1 || !\is_array($requestedDownloads[0])) {
            return null;
        }

        $recommendedDownload = $requestedDownloads[0];
        $recommendedFormatId = $recommendedDownload['format_id'] ?? null;
        if (!\is_string($recommendedFormatId) || $recommendedFormatId === '') {
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

        return \is_string($audioCodec)
            && $audioCodec !== ''
            && $audioCodec !== 'none'
            && \is_string($videoCodec)
            && $videoCodec !== ''
            && $videoCodec !== 'none';
    }

    /**
     * @param array<mixed> $format
     */
    private function hasKnownVideoCodec(array $format): bool
    {
        $videoCodec = $format['vcodec'] ?? null;

        return \is_string($videoCodec)
            && $videoCodec !== ''
            && $videoCodec !== 'none';
    }

    /**
     * @param array<mixed> $format
     */
    private function isAv1Format(array $format): bool
    {
        $videoCodec = $format['vcodec'] ?? null;

        return \is_string($videoCodec)
            && $videoCodec !== ''
            && \str_starts_with(\strtolower($videoCodec), 'av01');
    }

    /**
     * @param array<mixed> $format
     */
    private function isBrowserSafeMp4Format(array $format): bool
    {
        $extension = \strtolower((string) ($format['ext'] ?? ''));

        return (in_array($extension, ['', 'mp4', 'm4v'], true))
            && $this->isBrowserSafeVideoCodec($format)
            && $this->isBrowserSafeAudioCodec($format);
    }

    /**
     * @param array<mixed> $format
     */
    private function isBrowserSafeVideoCodec(array $format): bool
    {
        $videoCodec = \strtolower((string) ($format['vcodec'] ?? ''));

        return \str_starts_with($videoCodec, 'avc1')
            || \str_starts_with($videoCodec, 'h264')
            || \str_starts_with($videoCodec, 'h.264');
    }

    /**
     * @param array<mixed> $format
     */
    private function isBrowserSafeAudioCodec(array $format): bool
    {
        $audioCodec = \strtolower((string) ($format['acodec'] ?? ''));

        return \str_starts_with($audioCodec, 'mp4a')
            || \str_starts_with($audioCodec, 'aac');
    }

    /**
     * @param array<mixed> $formats
     * @return array<mixed>|null
     */
    private function findFormatById(string $formatId, mixed $formats): ?array
    {
        if (!\is_array($formats)) {
            return null;
        }

        foreach ($formats as $format) {
            if (!\is_array($format)) {
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
        return \is_int($value) || \is_float($value)
            ? (int) round($value)
            : 0;
    }

    private function isHlsProtocol(mixed $protocol): bool
    {
        return \is_string($protocol) && \str_contains($protocol, 'm3u8');
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

        // Форматы с неизвестной высотой (0) не отсекаем — пусть yt-dlp решит
        return $height === 0 || $height <= $maxHeight;
    }
}
