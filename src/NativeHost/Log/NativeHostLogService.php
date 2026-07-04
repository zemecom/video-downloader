<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Log;

use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use YtdPhp\Runtime\RuntimeBootstrap;

final readonly class NativeHostLogService
{
    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    public function append(string $message): void
    {
        $path = $this->bootstrap->getNativeHostLogPath();
        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($path));
        $filesystem->appendToFile($path, \sprintf("[%s] %s\n", \date('c'), $message));
    }

    public function appendException(Throwable $error): void
    {
        $this->append($error->getMessage() . "\n" . (string) $error);
    }
}
