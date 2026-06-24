<?php

declare(strict_types=1);

namespace YtdPhp\Download;

final readonly class DownloadMetadata
{
    /**
     * @param array<mixed> $payload
     */
    public function __construct(
        public string $infoJsonPath,
        public array $payload,
    ) {}
}
