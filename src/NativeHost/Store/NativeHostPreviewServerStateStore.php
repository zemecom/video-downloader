<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Store;

use DateTimeImmutable;
use JsonException;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Runtime\RuntimeBootstrap;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class NativeHostPreviewServerStateStore
{
    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $path = $this->bootstrap->getNativeHostPreviewServerStatePath();
        if (!\file_exists($path)) {
            return null;
        }

        try {
            $decoded = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    public function write(int $pid, int $port): void
    {
        $path = $this->bootstrap->getNativeHostPreviewServerStatePath();
        new Filesystem()->mkdir(\dirname($path));

        try {
            $encoded = \json_encode([
                'pid' => $pid,
                'port' => $port,
                'updatedAt' => new DateTimeImmutable()->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (JsonException) {
            return;
        }

        $tempPath = \sprintf('%s.%s.tmp', $path, \uniqid());
        if (\file_put_contents($tempPath, $encoded) === false) {
            return;
        }

        if (!\rename($tempPath, $path) && \file_exists($tempPath)) {
            \unlink($tempPath);
        }
    }

    public function clear(): void
    {
        new Filesystem()->remove($this->bootstrap->getNativeHostPreviewServerStatePath());
    }
}
