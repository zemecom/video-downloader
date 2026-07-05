<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Support;

use RuntimeException;

final class FakeDownloaderBinaries
{
    public static function ytDlp(): string
    {
        return self::load('yt-dlp.php');
    }

    public static function fastYtDlp(): string
    {
        return self::load('yt-dlp-fast.php');
    }

    public static function ytDlpWithTransient403(): string
    {
        return self::load('yt-dlp-transient-403.php');
    }

    public static function fastYtDlpWithTransientVideo403(): string
    {
        return self::load('yt-dlp-fast-transient-video-403.php');
    }

    public static function ffmpeg(): string
    {
        return self::load('ffmpeg.php');
    }

    public static function failingFfmpeg(): string
    {
        return self::load('ffmpeg-failing.php');
    }

    private static function load(string $fixtureName): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/bin/' . $fixtureName);
        if ($contents === false) {
            throw new RuntimeException('Unable to load fake binary fixture: ' . $fixtureName);
        }

        return $contents;
    }
}
