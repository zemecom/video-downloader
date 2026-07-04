<?php

declare(strict_types=1);

namespace YtdPhp\Playlist\Dto;

final readonly class SelectedItemMetadata
{
    public function __construct(
        public PlaylistItem $playlistItem,
        public string $infoJsonPath,
        public string $expectedPath,
        public string $resolvedFormatCode,
        public bool $exists,
        public ?int $filesize,
        public ?int $filesizeApprox,
        public bool $sizeKnown,
        public ?string $errorMessage = null,
    ) {}
}
