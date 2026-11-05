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

    public function testHasCookiesDetectsCookiesFlags(): void
    {
        $logger = new ConsoleLogger(new BufferedOutput());
        $client = new YtDlpClient($logger);
        $runner = new DownloadProcessRunner($client, $logger);

        self::assertTrue($runner->hasCookies(['yt-dlp', '--cookies', '/path/to/cookies.txt', 'https://example.com']));
        self::assertTrue($runner->hasCookies(['yt-dlp', '--cookies-from-browser', 'chrome', 'https://example.com']));
        self::assertFalse($runner->hasCookies(['yt-dlp', '-f', 'best', 'https://example.com']));
    }

    public function testPrepareNoCookiesCommandStripsCookiesOptions(): void
    {
        $logger = new ConsoleLogger(new BufferedOutput());
        $client = new YtDlpClient($logger);
        $runner = new DownloadProcessRunner($client, $logger);

        $command = [
            'yt-dlp',
            '--load-info-json',
            '/tmp/info.json',
            '--cookies',
            '/tmp/cookies.txt',
            '-f',
            '401+140',
            '-o',
            '/tmp/video.mp4',
        ];

        $result = $runner->prepareNoCookiesCommand($command, 'https://youtube.com/watch?v=abc');

        self::assertNotContains('--load-info-json', $result);
        self::assertNotContains('/tmp/info.json', $result);
        self::assertNotContains('--cookies', $result);
        self::assertNotContains('/tmp/cookies.txt', $result);
        self::assertContains('--no-cookies', $result);
        self::assertContains('--no-cache-dir', $result);
        self::assertContains('https://youtube.com/watch?v=abc', $result);
        self::assertContains('401+140', $result);
    }

    public function testPrepareVp9CommandStripsCookiesAndForcesVp9Sort(): void
    {
        $logger = new ConsoleLogger(new BufferedOutput());
        $client = new YtDlpClient($logger);
        $runner = new DownloadProcessRunner($client, $logger);

        $command = [
            'yt-dlp',
            '--cookies-from-browser',
            'chrome',
            '-f',
            '401+140',
            '-S',
            'res,fps',
            '-o',
            '/tmp/video.mp4',
        ];

        $result = $runner->prepareVp9Command($command, 'https://youtube.com/watch?v=abc');

        self::assertNotContains('--cookies-from-browser', $result);
        self::assertNotContains('chrome', $result);
        self::assertContains('--no-cookies', $result);
        self::assertNotContains('401+140', $result);
        self::assertContains('bestvideo+bestaudio/best', $result);
        self::assertContains('vcodec:vp9,res,fps', $result);
    }

    public function testPrepareNextAttemptCommandFollowsIntelligentFallbackChain(): void
    {
        $logger = new ConsoleLogger(new BufferedOutput());
        $client = new YtDlpClient($logger);
        $runner = new DownloadProcessRunner($client, $logger);

        $commandWithCookies = [
            'yt-dlp',
            '--cookies',
            '/tmp/cookies.txt',
            '-f',
            '401+140',
            '-o',
            '/tmp/video.mp4',
        ];

        // Attempt 2 with cookies initially: strips cookies, keeps original format
        $attempt2 = $runner->prepareNextAttemptCommand($commandWithCookies, 2, true, 'https://youtube.com/watch?v=abc');
        self::assertContains('--no-cookies', $attempt2);
        self::assertNotContains('--cookies', $attempt2);
        self::assertContains('401+140', $attempt2);
        self::assertNotContains('vcodec:vp9,res,fps', $attempt2);

        // Attempt 3 with cookies initially: strips cookies, forces VP9
        $attempt3 = $runner->prepareNextAttemptCommand($commandWithCookies, 3, true, 'https://youtube.com/watch?v=abc');
        self::assertContains('--no-cookies', $attempt3);
        self::assertContains('bestvideo+bestaudio/best', $attempt3);
        self::assertContains('vcodec:vp9,res,fps', $attempt3);

        $commandWithoutCookies = [
            'yt-dlp',
            '-f',
            '401+140',
            '-o',
            '/tmp/video.mp4',
        ];

        // Attempt 2 without cookies initially: immediately switches to VP9
        $attempt2NoCookies = $runner->prepareNextAttemptCommand($commandWithoutCookies, 2, false, 'https://youtube.com/watch?v=abc');
        self::assertContains('vcodec:vp9,res,fps', $attempt2NoCookies);
        self::assertContains('bestvideo+bestaudio/best', $attempt2NoCookies);
    }
}
