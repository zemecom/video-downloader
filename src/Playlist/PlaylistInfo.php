<?php

declare(strict_types=1);

namespace YtdPhp\Playlist;

final readonly class PlaylistInfo
{
    /**
     * @param list<PlaylistItem> $items
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $sourceUrl,
        public array $items,
        public int $totalCount,
        public int $knownTotalSize = 0,
        public int $unknownSizeCount = 0,
        public bool $sizesLoaded = false,
    ) {}
}
