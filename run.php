<?php

declare(strict_types=1);

use YtdPhp\Application;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\ErrorLogService;

require __DIR__ . '/vendor/autoload.php';

$bootstrap = new RuntimeBootstrap(__DIR__);
$bootstrap->normalizeGlobalArgv();
$bootstrap->ensureProjectRoot();
$bootstrap->initializeEnvironment();

try {
    $application = Application::createDefault($bootstrap)->toSymfonyApplication();

    return $application->run();
} catch (Throwable $error) {
    $errorLogPath = (new ErrorLogService($bootstrap))->appendExceptionTraceback($error);
    fwrite(STDERR, "💥 Необработанная ошибка: {$error->getMessage()}\n");
    fwrite(STDERR, "🧾 Полный traceback записан в: {$errorLogPath}\n");

    return 1;
}
