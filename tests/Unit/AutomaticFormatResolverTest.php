<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Service\AutomaticFormatResolver;

final class AutomaticFormatResolverTest extends TestCase
{
    public function testResolveUsesRequestedDownloadFormatForLiveStreams(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => true,
            'live_status' => 'is_live',
            'requested_downloads' => [
                ['format_id' => '301'],
            ],
        ]);

        self::assertSame('301', $resolved);
    }

    public function testResolveUsesRequestedDownloadFormatForPostLiveStreams(): void
    {
        $resolver = new AutomaticFormatResolver();

        $resolved = $resolver->resolve('best', [
            'is_live' => false,
            'live_status' => 'post_live',
            'requested_downloads' => [
                ['format_id' => '301'],
            ],
        ]);

        self::assertSame('301', $resolved);
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

    public function testResolveFallsBackToBestMuxedHlsFormatForWasLiveVideos(): void
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

        self::assertSame('301', $resolved);
    }
}
