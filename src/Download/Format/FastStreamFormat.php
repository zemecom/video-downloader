<?php

declare(strict_types=1);

namespace YtdPhp\Download\Format;

final readonly class FastStreamFormat
{
    public function __construct(
        public string $formatId,
        public string $extension,
    ) {}
}
