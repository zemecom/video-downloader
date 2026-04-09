<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class DoctorResult
{
    public function __construct(
        public string $status,
        public string $title,
        public string $details,
    ) {}
}
