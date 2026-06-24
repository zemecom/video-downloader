<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Download\FastStreamFormatResolver;

final class FastStreamFormatResolverTest extends TestCase
{
    public function testResolveBestSelectsSeparateVideoAndAudioStreams(): void
    {
        $resolver = new FastStreamFormatResolver();

        $pair = $resolver->resolve('best', [
            'formats' => [
                ['format_id' => '18', 'ext' => 'mp4', 'vcodec' => 'avc1.42001E', 'acodec' => 'mp4a.40.2', 'height' => 360, 'tbr' => 700],
                ['format_id' => '137', 'ext' => 'mp4', 'vcodec' => 'avc1.640028', 'acodec' => 'none', 'height' => 1080, 'fps' => 30, 'tbr' => 4500],
                ['format_id' => '140', 'ext' => 'm4a', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2', 'abr' => 128],
            ],
        ]);

        self::assertNotNull($pair);
        self::assertSame('137', $pair->video->formatId);
        self::assertSame('140', $pair->audio->formatId);
    }

    public function testResolveMediumAppliesHeightCap(): void
    {
        $resolver = new FastStreamFormatResolver();

        $pair = $resolver->resolve('medium', [
            'formats' => [
                ['format_id' => '137', 'ext' => 'mp4', 'vcodec' => 'avc1.640028', 'acodec' => 'none', 'height' => 1080, 'tbr' => 4500],
                ['format_id' => '136', 'ext' => 'mp4', 'vcodec' => 'avc1.4d401f', 'acodec' => 'none', 'height' => 720, 'tbr' => 2500],
                ['format_id' => '140', 'ext' => 'm4a', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2', 'abr' => 128],
            ],
        ]);

        self::assertNotNull($pair);
        self::assertSame('136', $pair->video->formatId);
    }

    public function testResolvePrefersNonAv1WhenRequested(): void
    {
        $resolver = new FastStreamFormatResolver();

        $pair = $resolver->resolve('best', [
            'formats' => [
                ['format_id' => '399', 'ext' => 'mp4', 'vcodec' => 'av01.0.08M.08', 'acodec' => 'none', 'height' => 1080, 'tbr' => 5000],
                ['format_id' => '137', 'ext' => 'mp4', 'vcodec' => 'avc1.640028', 'acodec' => 'none', 'height' => 1080, 'tbr' => 4500],
                ['format_id' => '140', 'ext' => 'm4a', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2', 'abr' => 128],
            ],
        ], true);

        self::assertNotNull($pair);
        self::assertSame('137', $pair->video->formatId);
    }

    public function testResolveMp4PrefersCompatibleAudioAndRejectsIncompatiblePairs(): void
    {
        $resolver = new FastStreamFormatResolver();

        $pair = $resolver->resolve('best', [
            'formats' => [
                ['format_id' => '315', 'ext' => 'mp4', 'vcodec' => 'hvc1.1.6.L120', 'acodec' => 'none', 'height' => 2160, 'tbr' => 8000],
                ['format_id' => '137', 'ext' => 'mp4', 'vcodec' => 'avc1.640028', 'acodec' => 'none', 'height' => 1080, 'tbr' => 4500],
                ['format_id' => '251', 'ext' => 'webm', 'vcodec' => 'none', 'acodec' => 'opus', 'abr' => 160],
                ['format_id' => '258', 'ext' => 'm4a', 'vcodec' => 'none', 'acodec' => 'alac', 'abr' => 256],
                ['format_id' => '140', 'ext' => 'm4a', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2', 'abr' => 128],
            ],
        ], false, 'mp4');

        self::assertNotNull($pair);
        self::assertSame('137', $pair->video->formatId);
        self::assertSame('140', $pair->audio->formatId);

        self::assertNull($resolver->resolve('best', [
            'formats' => [
                ['format_id' => '248', 'ext' => 'webm', 'vcodec' => 'vp9', 'acodec' => 'none', 'height' => 1080, 'tbr' => 4500],
                ['format_id' => '251', 'ext' => 'webm', 'vcodec' => 'none', 'acodec' => 'opus', 'abr' => 160],
            ],
        ], false, 'mp4'));
    }
}
