<?php

declare(strict_types=1);

namespace YtdPhp\Diagnostics;

final readonly class DoctorResult
{
    public function __construct(
        public string $status,
        public string $title,
        public string $details,
    ) {}
}
