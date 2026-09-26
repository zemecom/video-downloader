<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use YtdPhp\Download\Process\DownloadProcessRunner;
use YtdPhp\Download\YtDlp\YtDlpClient;
use YtdPhp\Shared\ConsoleLogger;

final class DownloadProcessRunnerTest extends TestCase
{
    public function testPrepareRetryCommandStripsLoadInfoJsonAndAddsNoCacheDirAndSourceUrl(): void
    {
        $logger = new ConsoleLogger(new BufferedOutput());
        $client = new YtDlpClient($logger);
        $runner = new DownloadProcessRunner($client, $logger);

        $command = [
            'yt-dlp',
            '--no-warnings',
            '--ignore-config',
            '--load-info-json',
            '/tmp/info.json',
            '-o',
            '/tmp/video.mp4',
        ];

        $retryCommand = $runner->prepareRetryCommand($command, 'https://youtube.com/watch?v=abc');

        self::assertNotContains('--load-info-json', $retryCommand);
        self::assertNotContains('/tmp/info.json', $retryCommand);
        self::assertContains('--no-cache-dir', $retryCommand);
        self::assertContains('https://youtube.com/watch?v=abc', $retryCommand);
        self::assertContains('-o', $retryCommand);
        self::assertContains('/tmp/video.mp4', $retryCommand);
    }

    public function testPrepareRetryCommandDoesNotDuplicateNoCacheDirOrSourceUrl(): void
    {
        $logger = new ConsoleLogger(new BufferedOutput());
        $client = new YtDlpClient($logger);
        $runner = new DownloadProcessRunner($client, $logger);

        $command = [
            'yt-dlp',
            '--no-cache-dir',
            'https://youtube.com/watch?v=abc',
        ];

        $retryCommand = $runner->prepareRetryCommand($command, 'https://youtube.com/watch?v=abc');

        self::assertSame($command, $retryCommand);
    }

    public function testIsRetryableHttp403FailureDetects403Errors(): void
    {
        $logger = new ConsoleLogger(new BufferedOutput());
        $client = new YtDlpClient($logger);
        $runner = new DownloadProcessRunner($client, $logger);

        $processWith403 = new Process(['php', '-r', 'fwrite(STDERR, "ERROR: unable to download video data: HTTP Error 403: Forbidden\n"); exit(1);']);
        $processWith403->run();

        self::assertTrue($runner->isRetryableHttp403Failure($processWith403));

        $processWith500 = new Process(['php', '-r', 'fwrite(STDERR, "ERROR: HTTP Error 500: Internal Server Error\n"); exit(1);']);
        $processWith500->run();

        self::assertFalse($runner->isRetryableHttp403Failure($processWith500));
    }
}
