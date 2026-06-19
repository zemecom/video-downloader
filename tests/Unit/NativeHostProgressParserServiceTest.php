<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Service\NativeHostProgressParserService;

final class NativeHostProgressParserServiceTest extends TestCase
{
    public function testParseDownloadLineExtractsPercentAndReadableText(): void
    {
        $parser = new NativeHostProgressParserService();

        $parsed = $parser->parse("[download]   48.5% of 10.00MiB at 2.00MiB/s ETA 00:04\n");

        self::assertSame('downloading', $parsed['status']);
        self::assertSame(48.5, $parsed['progressPercent']);
        self::assertStringContainsString('48.5%', $parsed['progressText']);
    }

    public function testParseDestinationLineProducesStartingStateWithoutPercent(): void
    {
        $parser = new NativeHostProgressParserService();

        $parsed = $parser->parse("[download] Destination: /tmp/video.mkv\n");

        self::assertSame('starting', $parsed['status']);
        self::assertNull($parsed['progressPercent']);
        self::assertStringContainsString('Destination', $parsed['progressText']);
    }

    public function testParseOutputFileLineExtractsDownloadedPath(): void
    {
        $parser = new NativeHostProgressParserService();

        $parsed = $parser->parse("📄 Файл: /tmp/My Video.mkv (11B)\n");

        self::assertSame('starting', $parsed['status']);
        self::assertSame('/tmp/My Video.mkv', $parsed['outputPath']);
    }

    public function testParseOutputFileLineExtractsPathAfterPromptPrefix(): void
    {
        $parser = new NativeHostProgressParserService();

        $parsed = $parser->parse("🔄 Перезаписать? [y/N]: 📄 Файл: /tmp/My Video.mkv (11B)\n");

        self::assertSame('starting', $parsed['status']);
        self::assertSame('/tmp/My Video.mkv', $parsed['outputPath']);
    }
}
