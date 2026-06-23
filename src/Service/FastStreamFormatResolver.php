<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use YtdPhp\Dto\FastStreamFormat;
use YtdPhp\Dto\FastStreamFormatPair;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function round;
use function str_starts_with;
use function strtolower;

final class FastStreamFormatResolver
{
    /**
     * @param array<mixed> $metadata
     */
    public function resolve(string $qualityPreset, array $metadata, bool $preferNonAv1 = false, string $outputFormat = 'mkv'): ?FastStreamFormatPair
    {
        $formats = $metadata['formats'] ?? null;
        if (!is_array($formats)) {
            return null;
        }

        $maxHeight = $this->qualityPresetMaxHeight($qualityPreset);
        $mp4Only = $outputFormat === 'mp4';
        $video = $this->selectBestVideoFormat($formats, $maxHeight, $preferNonAv1, $mp4Only);
        $audio = $this->selectBestAudioFormat($formats, $mp4Only);
        if ($video === null || $audio === null) {
            return null;
        }

        return new FastStreamFormatPair($video, $audio);
    }

    private function qualityPresetMaxHeight(string $qualityPreset): ?int
    {
        return match ($qualityPreset) {
            'medium' => 720,
            'low' => 480,
            default => null,
        };
    }

    /**
     * @param array<mixed> $formats
     */
    private function selectBestVideoFormat(array $formats, ?int $maxHeight, bool $preferNonAv1, bool $mp4Only): ?FastStreamFormat
    {
        $preferred = $this->findBestVideoFormat($formats, $maxHeight, $preferNonAv1, $mp4Only);
        if ($preferred !== null || !$preferNonAv1) {
            return $preferred;
        }

        return $this->findBestVideoFormat($formats, $maxHeight, false, $mp4Only);
    }

    /**
     * @param array<mixed> $formats
     */
    private function findBestVideoFormat(array $formats, ?int $maxHeight, bool $excludeAv1, bool $mp4Only): ?FastStreamFormat
    {
        $bestFormat = null;
        $bestScore = -1;

        foreach ($formats as $format) {
            if (!is_array($format) || !$this->isVideoOnlyFormat($format)) {
                continue;
            }

            if (($excludeAv1 && $this->isAv1Format($format))
                || !$this->matchesHeightCap($format, $maxHeight)
                || ($mp4Only && !$this->isMp4CompatibleVideo($format))) {
                continue;
            }

            $formatId = $this->formatId($format);
            $extension = $this->extension($format);
            if ($formatId === null || $extension === null) {
                continue;
            }

            $score = $this->buildVideoScore($format);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestFormat = new FastStreamFormat($formatId, $extension);
            }
        }

        return $bestFormat;
    }

    /**
     * @param array<mixed> $formats
     */
    private function selectBestAudioFormat(array $formats, bool $mp4Only): ?FastStreamFormat
    {
        $bestFormat = null;
        $bestScore = -1;

        foreach ($formats as $format) {
            if (!is_array($format) || !$this->isAudioOnlyFormat($format)) {
                continue;
            }

            if ($mp4Only && !$this->isMp4CompatibleAudio($format)) {
                continue;
            }

            $formatId = $this->formatId($format);
            $extension = $this->extension($format);
            if ($formatId === null || $extension === null) {
                continue;
            }

            $score = $this->buildAudioScore($format);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestFormat = new FastStreamFormat($formatId, $extension);
            }
        }

        return $bestFormat;
    }

    /**
     * @param array<mixed> $format
     */
    private function isVideoOnlyFormat(array $format): bool
    {
        return $this->hasCodec($format['vcodec'] ?? null)
            && !$this->hasCodec($format['acodec'] ?? null);
    }

    /**
     * @param array<mixed> $format
     */
    private function isAudioOnlyFormat(array $format): bool
    {
        return $this->hasCodec($format['acodec'] ?? null)
            && !$this->hasCodec($format['vcodec'] ?? null);
    }

    private function hasCodec(mixed $codec): bool
    {
        return is_string($codec) && $codec !== '' && $codec !== 'none';
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
    private function isMp4CompatibleVideo(array $format): bool
    {
        $extension = $this->extension($format);
        $videoCodec = strtolower((string) ($format['vcodec'] ?? ''));

        return ($extension === 'mp4' || $extension === 'm4v')
            && ($videoCodec === ''
                || str_starts_with($videoCodec, 'avc1')
                || str_starts_with($videoCodec, 'h264')
                || str_starts_with($videoCodec, 'h.264'));
    }

    /**
     * @param array<mixed> $format
     */
    private function isMp4CompatibleAudio(array $format): bool
    {
        $extension = $this->extension($format);
        $audioCodec = strtolower((string) ($format['acodec'] ?? ''));

        return ($extension === 'm4a' || $extension === 'mp4')
            && ($audioCodec === ''
                || str_starts_with($audioCodec, 'mp4a')
                || str_starts_with($audioCodec, 'aac'));
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

    /**
     * @param array<mixed> $format
     */
    private function buildVideoScore(array $format): int
    {
        $height = $this->normalizeNumber($format['height'] ?? null);
        $fps = $this->normalizeNumber($format['fps'] ?? null);
        $tbr = $this->normalizeNumber($format['tbr'] ?? null);

        return ($height * 1_000_000) + ($fps * 1_000) + $tbr;
    }

    /**
     * @param array<mixed> $format
     */
    private function buildAudioScore(array $format): int
    {
        $abr = $this->normalizeNumber($format['abr'] ?? null);
        $tbr = $this->normalizeNumber($format['tbr'] ?? null);
        $asr = $this->normalizeNumber($format['asr'] ?? null);

        return ($abr * 1_000_000) + ($tbr * 1_000) + $asr;
    }

    private function normalizeNumber(mixed $value): int
    {
        return is_int($value) || is_float($value)
            ? (int) round($value)
            : 0;
    }

    /**
     * @param array<mixed> $format
     */
    private function formatId(array $format): ?string
    {
        $formatId = $format['format_id'] ?? null;

        return is_string($formatId) && $formatId !== '' ? $formatId : null;
    }

    /**
     * @param array<mixed> $format
     */
    private function extension(array $format): ?string
    {
        $extension = $format['ext'] ?? null;
        if (!is_string($extension) || $extension === '') {
            return null;
        }

        return strtolower($extension);
    }
}
