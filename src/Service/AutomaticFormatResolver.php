<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use function count;
use function is_array;
use function is_float;
use function is_int;
use function str_contains;
use function is_string;

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
        if (!is_array($requestedDownloads) || count($requestedDownloads) !== 1 || !is_array($requestedDownloads[0])) {
            $fallbackFormatId = $this->resolveBestMuxedFormatId($metadata['formats'] ?? null);

            return $fallbackFormatId ?? $formatCode;
        }

        $recommendedFormatId = $requestedDownloads[0]['format_id'] ?? null;

        return is_string($recommendedFormatId) && $recommendedFormatId !== ''
            ? $recommendedFormatId
            : $formatCode;
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
            if (!is_array($format) || !$this->isMuxedFormat($format)) {
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
