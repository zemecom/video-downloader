<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class DownloadMetadataFetchResult
{
    private function __construct(
        public ?DownloadMetadata $metadata,
        public ?DownloadResult $failure,
    ) {}

    public static function success(DownloadMetadata $metadata): self
    {
        return new self($metadata, null);
    }

    public static function failure(DownloadResult $failure): self
    {
        return new self(null, $failure);
    }
}
