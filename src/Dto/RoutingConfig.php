<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class RoutingConfig
{
    /**
     * @param array<string, list<string>> $routing
     * @param list<RoutingRule> $orderedRules
     */
    public function __construct(
        public string $path,
        public array $routing,
        public array $orderedRules,
    ) {}
}
