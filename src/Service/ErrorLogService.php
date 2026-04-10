<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use YtdPhp\Bootstrap\RuntimeBootstrap;

use function date;
use function dirname;

final readonly class ErrorLogService
{
    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    public function appendExceptionTraceback(Throwable $error): string
    {
        $path = $this->bootstrap->getErrorLogPath();
        $directory = dirname($path);
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);

        $payload = sprintf(
            "[%s] Необработанная ошибка: %s\n%s\n\n",
            date('c'),
            $error->getMessage(),
            (string) $error,
        );
        $filesystem->appendToFile($path, $payload);

        return $path;
    }
}
