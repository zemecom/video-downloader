<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class SelectedItemMetadata
{
    public function __construct(
        public PlaylistItem $playlistItem,
        public string $infoJsonPath,
        public string $expectedPath,
        public bool $exists,
        public ?int $filesize,
        public ?int $filesizeApprox,
        public bool $sizeKnown,
        public ?string $errorMessage = null,
    ) {}
}
