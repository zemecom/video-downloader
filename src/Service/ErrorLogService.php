<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Throwable;
use YtdPhp\Bootstrap\RuntimeBootstrap;

use function date;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;

final readonly class ErrorLogService
{
    public function __construct(
        private RuntimeBootstrap $bootstrap,
    ) {}

    public function appendExceptionTraceback(Throwable $error): string
    {
        $path = $this->bootstrap->getErrorLogPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $payload = sprintf(
            "[%s] Необработанная ошибка: %s\n%s\n\n",
            date('c'),
            $error->getMessage(),
            (string) $error,
        );
        file_put_contents($path, $payload, FILE_APPEND);

        return $path;
    }
}
