<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class RoutingRule
{
    public function __construct(
        public string $section,
        public string $pattern,
        public bool $priority,
    ) {}
}
