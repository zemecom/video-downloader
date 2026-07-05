<?php

declare(strict_types=1);

namespace YtdPhp\Playlist\Dto;

final readonly class PlaylistSelectionSummary
{
    /**
     * @param list<SelectedItemMetadata> $selectedItems
     */
    public function __construct(
        public PlaylistInfo $playlist,
        public string $targetDir,
        public array $selectedItems,
        public int $knownTotalSize,
        public int $unknownSizeCount,
        public int $freeSpaceBytes,
        public int $concurrentDownloads,
        public int $preflightErrorCount,
    ) {}
}
