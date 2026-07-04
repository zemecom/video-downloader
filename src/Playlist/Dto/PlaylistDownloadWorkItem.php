<?php

declare(strict_types=1);

namespace YtdPhp\Playlist\Dto;

final readonly class PlaylistDownloadWorkItem
{
    public function __construct(
        public int $position,
        public SelectedItemMetadata $metadata,
    ) {}
}
