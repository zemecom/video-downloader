<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Download\Format\AutomaticFormatResolver;

final class AutomaticFormatResolverTest extends TestCase
{
    public function testResolveKeepsBestForLiveStreams(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => true,
            'live_status' => 'is_live',
            'requested_downloads' => [
                ['format_id' => '301'],
            ],
        ]);

        self::assertSame('best', $resolved);
    }

    public function testResolveUsesBestCappedMuxedFormatForMediumPreset(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('medium', [
            'formats' => [
                [
                    'format_id' => '18',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.42001E',
                    'height' => 360,
                    'fps' => 30,
                    'tbr' => 259,
                ],
                [
                    'format_id' => '22',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.64001F',
                    'height' => 720,
                    'fps' => 30,
                    'tbr' => 900,
                ],
                [
                    'format_id' => '37',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.640028',
                    'height' => 1080,
                    'fps' => 30,
                    'tbr' => 2500,
                ],
            ],
        ]);

        self::assertSame('22', $resolved);
    }

    public function testResolveKeepsPresetWhenNoCappedMuxedFormatExists(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('low', [
            'formats' => [
                [
                    'format_id' => '137',
                    'protocol' => 'https',
                    'acodec' => 'none',
                    'vcodec' => 'avc1.640028',
                    'height' => 1080,
                    'fps' => 30,
                    'tbr' => 2200,
                ],
                [
                    'format_id' => '140',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'none',
                    'height' => 0,
                    'fps' => 0,
                    'tbr' => 128,
                ],
            ],
        ]);

        self::assertSame('low', $resolved);
    }

    public function testResolveRejectsAv1RequestedDownloadForMediumYoutubePreset(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('medium', [
            'requested_downloads' => [
                ['format_id' => '401', 'vcodec' => 'av01.0.08M.08', 'height' => 720],
            ],
            'formats' => [
                [
                    'format_id' => '401',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'av01.0.08M.08',
                    'height' => 720,
                    'fps' => 30,
                    'tbr' => 900,
                ],
                [
                    'format_id' => '22',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.64001F',
                    'height' => 720,
                    'fps' => 30,
                    'tbr' => 850,
                ],
            ],
        ], true);

        self::assertSame('22', $resolved);
    }

    public function testResolveKeepsBestForMp4Output(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => true,
            'live_status' => 'is_live',
            'requested_downloads' => [
                ['format_id' => '616', 'ext' => 'mp4', 'vcodec' => 'vp09.00.51.08', 'acodec' => 'opus'],
            ],
            'formats' => [
                [
                    'format_id' => '616',
                    'ext' => 'mp4',
                    'protocol' => 'm3u8_native',
                    'acodec' => 'opus',
                    'vcodec' => 'vp09.00.51.08',
                    'height' => 1080,
                    'fps' => 30,
                    'tbr' => 2800,
                ],
                [
                    'format_id' => '22',
                    'ext' => 'mp4',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.64001F',
                    'height' => 720,
                    'fps' => 30,
                    'tbr' => 900,
                ],
            ],
        ], false, 'mp4');

        self::assertSame('best', $resolved);
    }

    public function testResolveKeepsBestForPostLiveStreams(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => false,
            'live_status' => 'post_live',
            'requested_downloads' => [
                ['format_id' => '301'],
            ],
        ]);

        self::assertSame('best', $resolved);
    }

    public function testResolveKeepsExplicitFormatChoice(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('bestaudio', [
            'is_live' => true,
            'live_status' => 'is_live',
            'requested_downloads' => [
                ['format_id' => '301'],
            ],
        ]);

        self::assertSame('bestaudio', $resolved);
    }

    public function testResolveKeepsBestWhenMetadataDoesNotExposeSingleRecommendedFormat(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => true,
            'live_status' => 'is_live',
            'requested_downloads' => [
                ['format_id' => '299'],
                ['format_id' => '140'],
            ],
        ]);

        self::assertSame('best', $resolved);
    }

    public function testResolveKeepsBestForWasLiveVideosWithMuxedFallback(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => false,
            'was_live' => true,
            'live_status' => 'was_live',
            'requested_downloads' => null,
            'formats' => [
                [
                    'format_id' => '18',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.42001E',
                    'height' => 360,
                    'fps' => 30,
                    'tbr' => 259,
                ],
                [
                    'format_id' => '300',
                    'protocol' => 'm3u8_native',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.640020',
                    'height' => 720,
                    'fps' => 60,
                    'tbr' => 1831,
                ],
                [
                    'format_id' => '301',
                    'protocol' => 'm3u8_native',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.64002A',
                    'height' => 1080,
                    'fps' => 60,
                    'tbr' => 3362,
                ],
                [
                    'format_id' => '299',
                    'protocol' => 'https',
                    'acodec' => 'none',
                    'vcodec' => 'avc1.64002a',
                    'height' => 1080,
                    'fps' => 60,
                    'tbr' => 1622,
                ],
            ],
        ]);

        self::assertSame('best', $resolved);
    }

    public function testResolveKeepsBestWhenLiveMetadataRecommendsAv1(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => true,
            'live_status' => 'is_live',
            'requested_downloads' => [
                ['format_id' => '401', 'vcodec' => 'av01.0.08M.08'],
            ],
            'formats' => [
                [
                    'format_id' => '401',
                    'protocol' => 'm3u8_native',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'av01.0.08M.08',
                    'height' => 1080,
                    'fps' => 60,
                    'tbr' => 3500,
                ],
                [
                    'format_id' => '301',
                    'protocol' => 'm3u8_native',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.64002A',
                    'height' => 1080,
                    'fps' => 60,
                    'tbr' => 3362,
                ],
            ],
        ], true);

        self::assertSame('best', $resolved);
    }

    public function testResolveKeepsBestForWasLiveVideosWithAv1Formats(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => false,
            'was_live' => true,
            'live_status' => 'was_live',
            'requested_downloads' => null,
            'formats' => [
                [
                    'format_id' => '401',
                    'protocol' => 'm3u8_native',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'av01.0.08M.08',
                    'height' => 1080,
                    'fps' => 60,
                    'tbr' => 3500,
                ],
                [
                    'format_id' => '301',
                    'protocol' => 'm3u8_native',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.64002A',
                    'height' => 1080,
                    'fps' => 60,
                    'tbr' => 3362,
                ],
            ],
        ], true);

        self::assertSame('best', $resolved);
    }

    public function testResolveAllowsUnknownHeight(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('medium', [
            'formats' => [
                [
                    'format_id' => '22',
                    'protocol' => 'https',
                    'acodec' => 'mp4a.40.2',
                    'vcodec' => 'avc1.64001F',
                    'height' => 0,
                    'fps' => 30,
                    'tbr' => 900,
                ],
            ],
        ]);

        self::assertSame('22', $resolved);
    }
}
