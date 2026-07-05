<?php

declare(strict_types=1);

namespace YtdPhp\Runtime;

use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use YtdPhp\Runtime\RuntimeBootstrap;

final readonly class ErrorLogService
{
    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    public function appendExceptionTraceback(Throwable $error): string
    {
        $path = $this->bootstrap->getErrorLogPath();
        $directory = \dirname($path);
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        $payload = sprintf(
            "[%s] Необработанная ошибка: %s\n%s\n\n",
            \date('c'),
            $error->getMessage(),
            (string) $error,
        );
        $filesystem->appendToFile($path, $payload);

        return $path;
    }
}
