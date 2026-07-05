<?php

declare(strict_types=1);

namespace YtdPhp\Download;

final readonly class DownloadResult
{
    public function __construct(
        public string $status,
        public ?string $detail = null,
    ) {}
}
