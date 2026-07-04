<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Playlist\Dto\PlaylistInfo;
use YtdPhp\Playlist\Dto\PlaylistItem;
use YtdPhp\Playlist\Dto\PlaylistSelectionSummary;
use YtdPhp\Runtime\RuntimeOptions;
use YtdPhp\Playlist\Dto\SelectedItemMetadata;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Download\DownloaderService;
use YtdPhp\Shared\InputPrompter;
use YtdPhp\Playlist\PlaylistService;
use YtdPhp\Download\YtDlp\YtDlpClient;

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

    public function testParsePlaylistSelectionSupportsAllKeywordForSelectableItemsOnly(): void
    {
        $service = $this->makeService();
        $items = [
            new PlaylistItem(1, 'One', 'https://example.com/1', 'available', true),
            new PlaylistItem(2, 'Two', 'https://example.com/2', 'private', false),
            new PlaylistItem(3, 'Three', 'https://example.com/3', 'available', true),
        ];

        self::assertSame([1, 3], $service->parsePlaylistSelection('all', $items));
    }

    public function testParsePlaylistSelectionDeduplicatesAndSortsIndexes(): void
    {
        $service = $this->makeService();
        $items = [
            new PlaylistItem(1, 'One', 'https://example.com/1', 'available', true),
            new PlaylistItem(2, 'Two', 'https://example.com/2', 'available', true),
            new PlaylistItem(3, 'Three', 'https://example.com/3', 'available', true),
        ];

        self::assertSame([1, 2, 3], $service->parsePlaylistSelection('3,1,2,3,2-3', $items));
    }

    public function testParsePlaylistSelectionRejectsDescendingRanges(): void
    {
        $service = $this->makeService();
        $items = [
            new PlaylistItem(1, 'One', 'https://example.com/1', 'available', true),
            new PlaylistItem(2, 'Two', 'https://example.com/2', 'available', true),
            new PlaylistItem(3, 'Three', 'https://example.com/3', 'available', true),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Начало диапазона не может быть больше конца.');

        $service->parsePlaylistSelection('3-1', $items);
    }

    public function testShouldTreatAsPlaylistReturnsFalseForExplicitSingleVideoUrl(): void
    {
        $service = $this->makeService();

        self::assertFalse($service->shouldTreatAsPlaylist(
            'https://www.youtube.com/watch?v=abc123&list=playlist-id',
            $this->makeRuntimeOptions(),
        ));
    }

    public function testPromptStorageConfirmationReturnsTrueForConfirmedAnswer(): void
    {
        ['service' => $service, 'prompter' => $prompter] = $this->makeServiceBundle();
        $prompter->setReader(static fn(string $prompt): string => 'y');

        self::assertTrue($service->promptStorageConfirmation($this->makeSummary()));
    }

    public function testPromptStorageConfirmationReturnsFalseForDefaultNegativeAnswer(): void
    {
        ['service' => $service, 'prompter' => $prompter] = $this->makeServiceBundle();
        $prompter->setReader(static fn(string $prompt): string => '');

        self::assertFalse($service->promptStorageConfirmation($this->makeSummary()));
    }

    public function testPromptOverwritePolicyReturnsOverwriteWhenNothingExists(): void
    {
        $service = $this->makeService();

        self::assertSame(
            PlaylistService::OVERWRITE_OVERWRITE_ALL,
            $service->promptOverwritePolicy($this->makeSummary(existing: false)),
        );
    }

    public function testPromptOverwritePolicyAcceptsShortSkipAnswer(): void
    {
        ['service' => $service, 'prompter' => $prompter] = $this->makeServiceBundle();
        $prompter->setReader(static fn(string $prompt): string => 's');

        self::assertSame(
            PlaylistService::OVERWRITE_SKIP_ALL,
            $service->promptOverwritePolicy($this->makeSummary(existing: true)),
        );
    }

    public function testPromptOverwritePolicyRepeatsUntilValidAnswer(): void
    {
        ['service' => $service, 'prompter' => $prompter] = $this->makeServiceBundle();
        $answers = ['???', 'cancel'];
        $prompter->setReader(static function (string $prompt) use (&$answers): string {
            return array_shift($answers) ?? '';
        });

        self::assertSame(
            PlaylistService::OVERWRITE_CANCEL,
            $service->promptOverwritePolicy($this->makeSummary(existing: true)),
        );
    }

    private function makeService(): PlaylistService
    {
        return $this->makeServiceBundle()['service'];
    }

    /**
     * @return array{service: PlaylistService, prompter: InputPrompter}
     */
    private function makeServiceBundle(): array
    {
        $bootstrap = new RuntimeBootstrap('/tmp/project');
        $logger = new ConsoleLogger();
        $prompter = new InputPrompter();
        $ytDlpClient = new YtDlpClient($logger);
        $downloader = new DownloaderService($ytDlpClient, $bootstrap, $logger, $prompter);

        return [
            'service' => new PlaylistService($ytDlpClient, $bootstrap, $downloader, $logger, $prompter),
            'prompter' => $prompter,
        ];
    }

    private function makeRuntimeOptions(): RuntimeOptions
    {
        return new RuntimeOptions(
            null,
            null,
            false,
            false,
            false,
            false,
            'best',
            false,
            false,
            1,
            20,
            null,
            null,
            '0.5',
            'mkv',
            'auto',
            'direct',
            null,
            'www.youtube.com',
        );
    }

    private function makeSummary(bool $existing = false): PlaylistSelectionSummary
    {
        $item = new PlaylistItem(1, 'One', 'https://example.com/1', 'available', true, 1024, null, true);
        $metadata = new SelectedItemMetadata(
            $item,
            '/tmp/item-1.info.json',
            '/tmp/item-1.mkv',
            'best',
            $existing,
            1024,
            null,
            true,
        );

        return new PlaylistSelectionSummary(
            new PlaylistInfo('playlist-1', 'Playlist', 'https://example.com/playlist', [$item], 1),
            '/tmp/playlist',
            [$metadata],
            1024,
            0,
            10 * 1024,
            1,
            0,
        );
    }
}
