<?php

declare(strict_types=1);

namespace YtdPhp\Download;

final readonly class FastStreamFormat
{
    public function __construct(
        public string $formatId,
        public string $extension,
    ) {}
}
