<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Service\YtDlpCommandBuilder;

final class YtDlpCommandBuilderTest extends TestCase
{
    public function testBuildForMetadataUsesNoPlaylistAndDumpJson(): void
    {
        $builder = new YtDlpCommandBuilder('https://youtube.com/watch?v=123');
        $command = $builder->setProxy('--proxy=http://local:8080')->setInsecure(true)->buildForMetadata();

        self::assertContains('--dump-json', $command);
        self::assertContains('--no-playlist', $command);
        self::assertContains('--proxy=http://local:8080', $command);
        self::assertContains('--no-check-certificate', $command);
    }

    public function testBuildForDownloadUsesMergedBestQualityByDefault(): void
    {
        $builder = new YtDlpCommandBuilder();
        $command = $builder->buildForDownload('best', '/tmp/video.%(ext)s', 'mp4');

        self::assertSame('bestvideo+bestaudio/best', $command[array_search('-f', $command, true) + 1]);
        self::assertContains('mp4', $command);
        self::assertNotContains('--newline', $command);
        self::assertContains('--concurrent-fragments', $command);
        self::assertContains('20', $command);
        self::assertContains('--progress-delta', $command);
        self::assertContains('0.5', $command);
    }

    public function testBuildForDownloadAcceptsCustomConcurrentFragments(): void
    {
        $builder = new YtDlpCommandBuilder();
        $command = $builder->buildForDownload('best', '/tmp/video.%(ext)s', 'mp4', false, 7);

        self::assertSame('7', $command[array_search('--concurrent-fragments', $command, true) + 1]);
    }

    public function testBuildForDownloadAcceptsCustomProgressDelta(): void
    {
        $builder = new YtDlpCommandBuilder();
        $command = $builder->buildForDownload('best', '/tmp/video.%(ext)s', 'mp4', false, 7, '1.75');

        self::assertSame('1.75', $command[array_search('--progress-delta', $command, true) + 1]);
    }

    public function testBuildForDownloadUsesNonAv1BestQualityForYoutubeUrls(): void
    {
        $builder = new YtDlpCommandBuilder('https://www.youtube.com/watch?v=123');
        $command = $builder->buildForDownload('best', '/tmp/video.%(ext)s', 'mp4');

        self::assertSame('bestvideo[vcodec!^=av01]+bestaudio/best[vcodec!^=av01]', $command[array_search('-f', $command, true) + 1]);
        self::assertContains('mp4', $command);
    }

    public function testBuildForDownloadUsesMediumQualityPreset(): void
    {
        $builder = new YtDlpCommandBuilder();
        $command = $builder->buildForDownload('medium', '/tmp/video.%(ext)s', 'mp4');

        self::assertSame('bestvideo[height<=720]+bestaudio/best[height<=720]/bestvideo+bestaudio/best', $command[array_search('-f', $command, true) + 1]);
    }

    public function testBuildForDownloadUsesLowQualityPresetForYoutubeUrls(): void
    {
        $builder = new YtDlpCommandBuilder('https://www.youtube.com/watch?v=123');
        $command = $builder->buildForDownload('low', '/tmp/video.%(ext)s', 'mp4');

        self::assertSame('bestvideo[vcodec!^=av01][height<=480]+bestaudio/best[vcodec!^=av01][height<=480]/bestvideo[vcodec!^=av01]+bestaudio/best[vcodec!^=av01]/bestvideo[height<=480]+bestaudio/best[height<=480]/bestvideo+bestaudio/best', $command[array_search('-f', $command, true) + 1]);
    }

    public function testBuildForDownloadDisablesFfmpegHttpPersistence(): void
    {
        $builder = new YtDlpCommandBuilder();
        $command = $builder->buildForDownload('301', '/tmp/video.%(ext)s', 'mp4');

        self::assertContains('--downloader-args', $command);
        self::assertContains('ffmpeg_i:-http_persistent 0', $command);
    }

    public function testBuildForDownloadExtractsBestAudioAsOpus(): void
    {
        $builder = new YtDlpCommandBuilder();
        $command = $builder->buildForDownload('bestaudio', '/tmp/audio.%(ext)s', 'mkv');

        self::assertSame('bestaudio/best', $command[array_search('-f', $command, true) + 1]);
        self::assertContains('--extract-audio', $command);
        self::assertContains('--audio-format', $command);
        self::assertContains('opus', $command);
    }

    public function testBuildForDownloadCanEnableLineBufferedProgressExplicitly(): void
    {
        $builder = new YtDlpCommandBuilder();
        $command = $builder->buildForDownload('best', '/tmp/video.%(ext)s', 'mp4', true);

        self::assertContains('--newline', $command);
    }
}
