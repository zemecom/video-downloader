<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\NativeHost\Job\NativeHostJobRunnerService;
use YtdPhp\NativeHost\Store\NativeHostJobStateStore;
use YtdPhp\NativeHost\Job\NativeHostProgressParserService;
use YtdPhp\NativeHost\Store\NativeHostRecentDownloadsStore;

final class NativeHostJobRunnerServiceTest extends TestCase
{
    public function testNativeHostJobScriptUsesExistingWorkerClasses(): void
    {
        $script = (string) \file_get_contents(\dirname(__DIR__, 2) . '/bin/ytd-native-job');

        self::assertStringContainsString('use YtdPhp\\NativeHost\\Job\\NativeHostJobRunnerService;', $script);
        self::assertStringContainsString('use YtdPhp\\NativeHost\\Job\\NativeHostProgressParserService;', $script);
        self::assertStringContainsString('use YtdPhp\\NativeHost\\Store\\NativeHostJobStateStore;', $script);
        self::assertStringContainsString('use YtdPhp\\NativeHost\\Store\\NativeHostRecentDownloadsStore;', $script);
        self::assertStringNotContainsString('use YtdPhp\\NativeHost\\NativeHostJobRunnerService;', $script);
        self::assertStringNotContainsString('use YtdPhp\\NativeHost\\NativeHostProgressParserService;', $script);
        self::assertStringNotContainsString('use YtdPhp\\NativeHost\\NativeHostJobStateStore;', $script);
        self::assertStringNotContainsString('use YtdPhp\\NativeHost\\NativeHostRecentDownloadsStore;', $script);
        self::assertStringNotContainsString('use YtdPhp\\NativeHost\\NativeHostRecentDownloadsStore;', $script);
    }

    public function testRunForVideoInvokesCliWithMp4FlagForBrowserPreview(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_runner_' . \uniqid();
        \mkdir($root . '/bin', 0777, true);
        \mkdir($root . '/downloads', 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $argvPath = $root . '/downloads/argv.txt';
            \file_put_contents($root . '/bin/ytd', <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__DIR__ . '/../downloads/argv.txt', json_encode($argv, JSON_UNESCAPED_SLASHES));
echo "📄 Файл: " . __DIR__ . "/../downloads/fake-preview.mp4 (7B)\n";
PHP);
            \chmod($root . '/bin/ytd', 0777);

            $bootstrap = new RuntimeBootstrap($root);
            $runner = new NativeHostJobRunnerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                new NativeHostProgressParserService(),
                new NativeHostRecentDownloadsStore($bootstrap),
            );

            $exitCode = $runner->run('job-123', 'https://example.com/watch?v=42', 'video');
            $argv = \json_decode((string) \file_get_contents($argvPath), true);

            self::assertSame(0, $exitCode);
            self::assertContains('--mp4', $argv);
            self::assertContains('https://example.com/watch?v=42', $argv);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRunStoresRecentDownloadWhenOutputPathIsPrintedRightBeforeProcessExit(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_runner_tail_' . \uniqid();
        \mkdir($root . '/bin', 0777, true);
        \mkdir($root . '/downloads', 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            \file_put_contents($root . '/bin/ytd', <<<'PHP'
#!/usr/bin/env php
<?php
$path = __DIR__ . '/../downloads/final-output.opus';
touch($path);
fwrite(STDOUT, "📄 Файл: {$path} (7B)");
PHP);
            \chmod($root . '/bin/ytd', 0777);

            $bootstrap = new RuntimeBootstrap($root);
            $store = new NativeHostJobStateStore($bootstrap);
            $runner = new NativeHostJobRunnerService(
                $bootstrap,
                $store,
                new NativeHostProgressParserService(),
                new NativeHostRecentDownloadsStore($bootstrap),
            );

            $exitCode = $runner->run('job-tail', 'https://example.com/watch?v=tail', 'audio');
            $state = $store->read('job-tail');
            $recentDownloads = new NativeHostRecentDownloadsStore($bootstrap)->list();

            self::assertSame(0, $exitCode);
            self::assertSame(\realpath($root . '/downloads/final-output.opus'), \realpath((string) ($state['outputPath'] ?? '')));
            self::assertIsString($state['recentDownloadId'] ?? null);
            self::assertCount(1, $recentDownloads);
            self::assertSame(\realpath($root . '/downloads/final-output.opus'), \realpath((string) ($recentDownloads[0]['path'] ?? '')));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRunStopsDownloadProcessWhenCancellationIsRequestedBeforeOutputIsReady(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_runner_cancel_' . \uniqid();
        \mkdir($root . '/bin', 0777, true);
        \mkdir($root . '/downloads', 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            \file_put_contents($root . '/bin/ytd', <<<'PHP'
#!/usr/bin/env php
<?php
fwrite(STDOUT, "[download]   12.0% of 10.00MiB at 2.00MiB/s ETA 00:05\n");
fflush(STDOUT);
usleep(1800000);
file_put_contents(__DIR__ . '/../downloads/natural-exit.txt', 'done');
fwrite(STDOUT, "[download]   80.0% of 10.00MiB at 2.00MiB/s ETA 00:01\n");
PHP);
            \chmod($root . '/bin/ytd', 0777);

            $runnerScript = \sprintf(
                <<<'PHP'
require %s;
putenv(%s);
$root = %s;
$bootstrap = new \YtdPhp\Runtime\RuntimeBootstrap($root);
$runner = new \YtdPhp\NativeHost\Job\NativeHostJobRunnerService(
    $bootstrap,
    new \YtdPhp\NativeHost\Store\NativeHostJobStateStore($bootstrap),
    new \YtdPhp\NativeHost\Job\NativeHostProgressParserService(),
    new \YtdPhp\NativeHost\Store\NativeHostRecentDownloadsStore($bootstrap),
);
exit($runner->run('job-cancel', 'https://example.com/watch?v=cancel', 'video'));
PHP,
                var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
                var_export('YTD_PROJECT_ROOT=' . $root, true),
                var_export($root, true),
            );

            $process = new Process([PHP_BINARY, '-r', $runnerScript]);
            $process->start();

            $bootstrap = new RuntimeBootstrap($root);
            $store = new NativeHostJobStateStore($bootstrap);
            $deadline = \microtime(true) + 2.0;
            do {
                $state = $store->read('job-cancel');
                if (\is_int($state['downloadPid'] ?? null)) {
                    break;
                }
                \usleep(20000);
            } while (\microtime(true) < $deadline);

            $store->requestCancel('job-cancel');

            $process->wait();
            $state = $store->read('job-cancel');

            self::assertSame(0, $process->getExitCode());
            self::assertSame('cancelled', $state['status'] ?? null);
            self::assertSame('Загрузка отменена.', $state['progressText'] ?? null);
            self::assertFalse($store->cancelRequested('job-cancel'));
            self::assertFileDoesNotExist($root . '/downloads/natural-exit.txt');
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testRunKeepsCompletedStateWhenCancellationArrivesAfterOutputIsReady(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_runner_late_cancel_' . \uniqid();
        \mkdir($root . '/bin', 0777, true);
        \mkdir($root . '/downloads', 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            \file_put_contents($root . '/bin/ytd', <<<'PHP'
#!/usr/bin/env php
<?php
$path = __DIR__ . '/../downloads/late-cancel.mp4';
usleep(150000);
touch($path);
fwrite(STDOUT, "📄 Файл: {$path} (7B)\n");
usleep(300000);
PHP);
            \chmod($root . '/bin/ytd', 0777);

            $runnerScript = \sprintf(
                <<<'PHP'
require %s;
putenv(%s);
$root = %s;
$bootstrap = new \YtdPhp\Runtime\RuntimeBootstrap($root);
$runner = new \YtdPhp\NativeHost\Job\NativeHostJobRunnerService(
    $bootstrap,
    new \YtdPhp\NativeHost\Store\NativeHostJobStateStore($bootstrap),
    new \YtdPhp\NativeHost\Job\NativeHostProgressParserService(),
    new \YtdPhp\NativeHost\Store\NativeHostRecentDownloadsStore($bootstrap),
);
exit($runner->run('job-late-cancel', 'https://example.com/watch?v=late-cancel', 'video'));
PHP,
                var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
                var_export('YTD_PROJECT_ROOT=' . $root, true),
                var_export($root, true),
            );

            $process = new Process([PHP_BINARY, '-r', $runnerScript]);
            $process->start();

            \usleep(250000);
            $bootstrap = new RuntimeBootstrap($root);
            $store = new NativeHostJobStateStore($bootstrap);
            $store->requestCancel('job-late-cancel');

            $process->wait();
            $state = $store->read('job-late-cancel');
            $recentDownloads = new NativeHostRecentDownloadsStore($bootstrap)->list();

            self::assertSame(0, $process->getExitCode());
            self::assertSame('completed', $state['status'] ?? null);
            self::assertSame('Загрузка завершена.', $state['progressText'] ?? null);
            self::assertFalse($store->cancelRequested('job-late-cancel'));
            self::assertIsString($state['recentDownloadId'] ?? null);
            self::assertCount(1, $recentDownloads);
            self::assertSame(\realpath($root . '/downloads/late-cancel.mp4'), \realpath((string) ($recentDownloads[0]['path'] ?? '')));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
