<?php

declare(strict_types=1);

use YtdPhp\Application;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Runtime\ErrorLogService;

if (! function_exists('ytd_run')) {
    function ytd_run(string $projectRoot): int
    {
        require $projectRoot . '/vendor/autoload.php';

        $bootstrap = new RuntimeBootstrap($projectRoot);
        $bootstrap->normalizeGlobalArgv();
        $bootstrap->ensureProjectRoot();
        $bootstrap->initializeEnvironment();

        try {
            $application = Application::createDefault($bootstrap)->toSymfonyApplication();

            return $application->run();
        } catch (Throwable $error) {
            $errorLogPath = new ErrorLogService($bootstrap)->appendExceptionTraceback($error);
            fwrite(STDERR, "💥 Необработанная ошибка: {$error->getMessage()}\n");
            fwrite(STDERR, "🧾 Полный traceback записан в: {$errorLogPath}\n");

            return 1;
        }
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(ytd_run(__DIR__));
}
