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
    public function resolve(string $formatCode, array $metadata): string
    {
        if ($formatCode !== 'best' || !$this->shouldPreferRecommendedDownload($metadata)) {
            return $formatCode;
        }

        $requestedDownloads = $metadata['requested_downloads'] ?? null;
        $recommendedFormatId = $this->resolvePreferredRequestedDownloadFormatId(
            $requestedDownloads,
            $metadata['formats'] ?? null,
        );
        if ($recommendedFormatId !== null) {
            return $recommendedFormatId;
        }

        $fallbackFormatId = $this->resolveBestMuxedFormatId($metadata['formats'] ?? null);

        return $fallbackFormatId ?? $formatCode;
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

    private function resolveBestMuxedFormatId(mixed $formats): ?string
    {
        if (!is_array($formats)) {
            return null;
        }

        $bestHlsFormatId = null;
        $bestHlsScore = -1;
        $bestMuxedFormatId = null;
        $bestMuxedScore = -1;

        foreach ($formats as $format) {
            if (!is_array($format) || !$this->isMuxedFormat($format) || $this->isAv1Format($format)) {
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

    private function resolvePreferredRequestedDownloadFormatId(mixed $requestedDownloads, mixed $formats): ?string
    {
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
            return $this->isAv1Format($knownFormat)
                ? null
                : $recommendedFormatId;
        }

        if ($this->hasKnownVideoCodec($recommendedDownload) && $this->isAv1Format($recommendedDownload)) {
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
}
