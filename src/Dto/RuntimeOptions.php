<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class RuntimeOptions
{
    public function __construct(
        public ?string $proxyUrl,
        public ?string $currentProxy,
        public bool $insecure,
        public bool $manualMode,
        public bool $audioOnly,
        public string $qualityPreset,
        public bool $dryRun,
        public bool $playlistShowSizes,
        public int $concurrentDownloads,
        public int $concurrentFragments,
        public ?string $downloadDir,
        public ?bool $progressNewline,
        public string $progressDelta,
        public string $outputFormat,
        public string $routeMode,
        public string $matchedSection,
        public ?string $matchedPattern,
        public string $hostname,
    ) {}
}
