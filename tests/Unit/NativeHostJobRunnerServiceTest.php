<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\NativeHostJobRunnerService;
use YtdPhp\Service\NativeHostJobStateStore;
use YtdPhp\Service\NativeHostProgressParserService;
use YtdPhp\Service\NativeHostRecentDownloadsStore;

use function chmod;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

final class NativeHostJobRunnerServiceTest extends TestCase
{
    public function testRunForVideoInvokesCliWithMp4FlagForBrowserPreview(): void
    {
        $root = sys_get_temp_dir() . '/ytd_native_runner_' . uniqid();
        mkdir($root . '/bin', 0777, true);
        mkdir($root . '/downloads', 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $argvPath = $root . '/downloads/argv.txt';
            file_put_contents($root . '/bin/ytd', <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__DIR__ . '/../downloads/argv.txt', json_encode($argv, JSON_UNESCAPED_SLASHES));
echo "📄 Файл: " . __DIR__ . "/../downloads/fake-preview.mp4 (7B)\n";
PHP);
            chmod($root . '/bin/ytd', 0777);

            $bootstrap = new RuntimeBootstrap($root);
            $runner = new NativeHostJobRunnerService(
                $bootstrap,
                new NativeHostJobStateStore($bootstrap),
                new NativeHostProgressParserService(),
                new NativeHostRecentDownloadsStore($bootstrap),
            );

            $exitCode = $runner->run('job-123', 'https://example.com/watch?v=42', 'video');
            $argv = json_decode((string) file_get_contents($argvPath), true);

            self::assertSame(0, $exitCode);
            self::assertContains('--mp4', $argv);
            self::assertContains('https://example.com/watch?v=42', $argv);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
