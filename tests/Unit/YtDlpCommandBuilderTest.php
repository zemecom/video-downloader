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
    }
}
