<?php

declare(strict_types=1);

namespace YtdPhp\Playlist\Dto;

final readonly class PlaylistItem
{
    public function __construct(
        public int $playlistIndex,
        public string $title,
        public string $url,
        public string $status,
        public bool $selectable = true,
        public ?int $filesize = null,
        public ?int $filesizeApprox = null,
        public bool $sizeKnown = false,
    ) {}
}
