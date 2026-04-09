<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Dto\PlaylistItem;
use YtdPhp\Service\ConsoleLogger;
use YtdPhp\Service\DownloaderService;
use YtdPhp\Service\InputPrompter;
use YtdPhp\Service\PlaylistService;
use YtdPhp\Service\YtDlpClient;

final class PlaylistServiceTest extends TestCase
{
    public function testParsePlaylistSelectionSupportsRangesAndIndividualNumbers(): void
    {
        $service = $this->makeService();
        $items = [
            new PlaylistItem(1, 'One', 'https://example.com/1', 'available', true),
            new PlaylistItem(2, 'Two', 'https://example.com/2', 'available', true),
            new PlaylistItem(3, 'Three', 'https://example.com/3', 'available', true),
            new PlaylistItem(4, 'Four', 'https://example.com/4', 'available', false),
        ];

        self::assertSame([1, 2, 3], $service->parsePlaylistSelection('1,2-3', $items));
    }

    public function testParsePlaylistSelectionRejectsUnavailableItem(): void
    {
        $service = $this->makeService();
        $items = [
            new PlaylistItem(1, 'One', 'https://example.com/1', 'available', true),
            new PlaylistItem(2, 'Two', 'https://example.com/2', 'private', false),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $service->parsePlaylistSelection('2', $items);
    }

    private function makeService(): PlaylistService
    {
        $bootstrap = new RuntimeBootstrap('/tmp/project');
        $logger = new ConsoleLogger();
        $prompter = new InputPrompter();
        $ytDlpClient = new YtDlpClient($logger);
        $downloader = new DownloaderService($ytDlpClient, $bootstrap, $logger, $prompter);

        return new PlaylistService($ytDlpClient, $bootstrap, $downloader, $logger, $prompter);
    }
}
