<?php

declare(strict_types=1);

namespace YtdPhp\Download;

use YtdPhp\Runtime\RuntimeOptions;

final readonly class DownloadOptions
{
    public function __construct(
        public ?string $proxy = null,
        public bool $insecure = false,
        public string $outputFormat = 'mkv',
        public bool $dryRun = false,
        public ?int $concurrentFragments = null,
        public ?string $downloadDir = null,
        public ?bool $progressNewline = null,
        public ?string $progressDelta = null,
        public bool $emitElapsedRuntime = true,
        public bool $forceOverwrites = false,
        public bool $emitLogs = true,
        public bool $allow4k = false,
    ) {}

    public static function fromRuntimeOptions(RuntimeOptions $options): self
    {
        return new self(
            proxy: $options->currentProxy,
            insecure: $options->insecure,
            outputFormat: $options->outputFormat,
            dryRun: $options->dryRun,
            concurrentFragments: $options->concurrentFragments,
            downloadDir: $options->downloadDir,
            progressNewline: $options->progressNewline,
            progressDelta: $options->progressDelta,
            allow4k: $options->allow4k,
        );
    }

    public function with(
        ?string $downloadDir = null,
        ?bool $forceOverwrites = null,
        ?bool $emitLogs = null,
        ?bool $emitElapsedRuntime = null,
    ): self {
        return new self(
            proxy: $this->proxy,
            insecure: $this->insecure,
            outputFormat: $this->outputFormat,
            dryRun: $this->dryRun,
            concurrentFragments: $this->concurrentFragments,
            downloadDir: $downloadDir ?? $this->downloadDir,
            progressNewline: $this->progressNewline,
            progressDelta: $this->progressDelta,
            emitElapsedRuntime: $emitElapsedRuntime ?? $this->emitElapsedRuntime,
            forceOverwrites: $forceOverwrites ?? $this->forceOverwrites,
            emitLogs: $emitLogs ?? $this->emitLogs,
            allow4k: $this->allow4k,
        );
    }
}
