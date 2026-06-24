<?php

declare(strict_types=1);

namespace YtdPhp\Routing;

final readonly class RouteDecision
{
    public function __construct(
        public string $mode,
        public ?string $proxyUrl,
        public string $matchedSection,
        public ?string $matchedPattern,
        public string $hostname,
    ) {}
}
