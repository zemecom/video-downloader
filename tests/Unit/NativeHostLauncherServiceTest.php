<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\NativeHostLauncherService;

final class NativeHostLauncherServiceTest extends TestCase
{
    public function testLaunchBuildsCommandUsingArgumentArrayWithoutShellInterpolation(): void
    {
        $projectRoot = '/tmp/project';
        $capturedCommand = null;
        $capturedLogPath = null;
        putenv('YTD_PROJECT_ROOT=' . $projectRoot);

        try {
            $launcher = new NativeHostLauncherService(
                new RuntimeBootstrap($projectRoot),
                static function (array $command, string $logPath) use (&$capturedCommand, &$capturedLogPath): void {
                    $capturedCommand = $command;
                    $capturedLogPath = $logPath;
                },
            );

            $launcher->launch('https://example.com/watch?v=42&name=$(whoami)');

            self::assertSame(
                [
                    PHP_BINARY,
                    $projectRoot . '/bin/ytd',
                    'https://example.com/watch?v=42&name=$(whoami)',
                ],
                $capturedCommand,
            );
            self::assertSame($projectRoot . '/logs/native-host.log', $capturedLogPath);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
