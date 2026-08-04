<?php

declare(strict_types=1);

namespace YtdPhp\Download\YtDlp;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

interface YtDlpGateway
{
    public function checkBinary(): void;

    /**
     * @param list<string> $command
     * @return array<mixed>
     */
    public function runJson(array $command): array;

    /**
     * @param list<string> $command
     */
    public function runLive(array $command, bool $passthrough = true): Process;

    /**
     * @param list<string> $command
     */
    public function runCaptured(array $command): Process;

    public function listFormats(string $videoUrl, ?string $proxy = null, bool $insecure = false): bool;

    public function getExpectedFilename(
        ?string $videoUrl,
        string $formatCode,
        string $outputPath,
        ?string $proxy = null,
        bool $insecure = false,
        ?string $infoJsonPath = null,
        string $outputFormat = 'mkv',
        bool $allow4k = false,
    ): ?string;

    public function getProcessErrorDetail(Process|ProcessFailedException $processOrException, string $fallback = 'command_failed'): string;
}
