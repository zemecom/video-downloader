<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Download\DownloaderService;
use YtdPhp\Shared\InputPrompter;
use YtdPhp\Download\YtDlp\YtDlpClient;
use YtdPhp\Tests\Support\FakeDownloaderBinaries;

final class DownloaderServiceTest extends TestCase
{
    public function testDownloadVideoReplacesSpacesWithUnderscoresInOutputFilename(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, FakeDownloaderBinaries::ytDlp());
        \chmod($scriptPath, 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $logs = $output->fetch();

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video.mkv');
            self::assertFalse(\file_exists($downloadDir . '/My Cool Video.mkv'));
            self::assertFileExists($root . '/last-info-json.txt');

            $infoJsonPath = trim((string) \file_get_contents($root . '/last-info-json.txt'));
            self::assertNotSame('', $infoJsonPath);
            self::assertFalse(\file_exists($infoJsonPath));
            self::assertStringContainsString('⏱️ Время работы:', $logs);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoUsesTerminalLinkSafeOutputFilename(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_link_safe_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        $scriptSource = \str_replace(
            "'title' => 'My Cool Video'",
            "'title' => ' 100% [Real] Video： Test? '",
            FakeDownloaderBinaries::ytDlp(),
        );
        \file_put_contents($scriptPath, $scriptSource);
        \chmod($scriptPath, 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $logs = $output->fetch();
            $expectedFile = $downloadDir . '/100_Real_Video_Test.mkv';

            self::assertSame('completed', $result->status);
            self::assertFileExists($expectedFile);
            self::assertStringContainsString('📄 Файл: ' . $expectedFile, $logs);
            self::assertFalse(\file_exists($downloadDir . '/ 100% [Real] Video： Test? .mkv'));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoLogsExistingOutputPathWhenOverwriteIsDeclined(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_skip_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, FakeDownloaderBinaries::ytDlp());
        \chmod($scriptPath, 0777);

        $existingFile = $downloadDir . '/My_Cool_Video.mkv';
        \file_put_contents($existingFile, 'video-bytes');

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $prompter->setReader(static fn(): string => '');
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $logs = $output->fetch();

            self::assertSame('skipped', $result->status);
            self::assertStringContainsString('📄 Файл: ' . $existingFile, $logs);
            self::assertStringContainsString('📂 Каталог: ' . $downloadDir, $logs);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoEnablesLineBufferedProgressWhenRequestedByEnvironment(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_progress_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, FakeDownloaderBinaries::ytDlp());
        \chmod($scriptPath, 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');
        $previousProgressNewline = \getenv('YTD_PROGRESS_NEWLINE');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);
        putenv('YTD_PROGRESS_NEWLINE=1');

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $logger = new ConsoleLogger();
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $lastDownloadArgs = \json_decode((string) \file_get_contents($root . '/last-download-args.json'), true);

            self::assertSame('completed', $result->status);
            self::assertIsArray($lastDownloadArgs);
            self::assertContains('--newline', $lastDownloadArgs);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }

            if ($previousProgressNewline === false) {
                putenv('YTD_PROGRESS_NEWLINE');
            } else {
                putenv('YTD_PROGRESS_NEWLINE=' . $previousProgressNewline);
            }
        }
    }

    public function testDownloadVideoAllowsManualDirectoryAndProgressOverrides(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_manual_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads-default';
        $customDir = $root . '/downloads-custom';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, FakeDownloaderBinaries::ytDlp());
        \chmod($scriptPath, 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');
        $previousProgressNewline = \getenv('YTD_PROGRESS_NEWLINE');
        $previousProgressDelta = \getenv('YTD_PROGRESS_DELTA');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);
        putenv('YTD_PROGRESS_NEWLINE');
        putenv('YTD_PROGRESS_DELTA');

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $logger = new ConsoleLogger();
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo(
                'https://example.com/video',
                'best',
                new \YtdPhp\Download\DownloadOptions(
                    outputFormat: 'mkv',
                    concurrentFragments: 11,
                    downloadDir: $customDir,
                    progressNewline: true,
                    progressDelta: '1.75',
                ),
            );
            $lastDownloadArgs = \json_decode((string) \file_get_contents($root . '/last-download-args.json'), true);

            self::assertSame('completed', $result->status);
            self::assertFileExists($customDir . '/My_Cool_Video.mkv');
            self::assertIsArray($lastDownloadArgs);
            self::assertContains('--newline', $lastDownloadArgs);
            self::assertSame('11', $lastDownloadArgs[array_search('--concurrent-fragments', $lastDownloadArgs, true) + 1]);
            self::assertSame('1.75', $lastDownloadArgs[array_search('--progress-delta', $lastDownloadArgs, true) + 1]);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }

            if ($previousProgressNewline === false) {
                putenv('YTD_PROGRESS_NEWLINE');
            } else {
                putenv('YTD_PROGRESS_NEWLINE=' . $previousProgressNewline);
            }

            if ($previousProgressDelta === false) {
                putenv('YTD_PROGRESS_DELTA');
            } else {
                putenv('YTD_PROGRESS_DELTA=' . $previousProgressDelta);
            }
        }
    }

    public function testDownloadVideoRendersProgressBarForMainMode(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_main_progress_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, FakeDownloaderBinaries::ytDlp());
        \chmod($scriptPath, 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $logs = $output->fetch();

            self::assertSame('completed', $result->status);
            self::assertStringContainsString("\033[38;5;67m│ ◈ DOWNLOAD", $logs);
            self::assertStringContainsString('◈ DOWNLOAD  ⟦▰▰▰▰▰▰▰▰▰▰▰▰▰▱', $logs);
            self::assertStringContainsString('50.0%  10.00MiB · 2.00MiB/s · ETA 00:05', $logs);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoRetriesHttp403WithResume(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_retry_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, FakeDownloaderBinaries::ytDlpWithTransient403());
        \chmod($scriptPath, 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $logs = $output->fetch();
            $lastDownloadArgs = \json_decode((string) \file_get_contents($root . '/last-download-args-2.json'), true);

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video.mkv');
            self::assertFalse(\file_exists($downloadDir . '/My_Cool_Video.mkv.part'));
            self::assertSame('2', trim((string) \file_get_contents($root . '/download-attempts.txt')));
            self::assertFileExists($root . '/download-resume-seen.txt');
            self::assertIsArray($lastDownloadArgs);
            self::assertContains('--continue', $lastDownloadArgs);
            self::assertStringContainsString('повторяю попытку 2/3 с докачкой', $logs);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoFastDownloadsSeparateStreamsAndMergesWithFfmpeg(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_fast_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        \file_put_contents($binDir . '/yt-dlp', FakeDownloaderBinaries::fastYtDlp());
        \chmod($binDir . '/yt-dlp', 0777);
        \file_put_contents($binDir . '/ffmpeg', FakeDownloaderBinaries::ffmpeg());
        \chmod($binDir . '/ffmpeg', 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideoFast(
                'https://example.com/video',
                'best',
                new \YtdPhp\Download\DownloadOptions(
                    outputFormat: 'mp4',
                    concurrentFragments: 6,
                ),
            );
            $logs = $output->fetch();

            $videoArgs = \json_decode((string) \file_get_contents($root . '/stream-137-args.json'), true);
            $audioArgs = \json_decode((string) \file_get_contents($root . '/stream-251-args.json'), true);
            $ffmpegArgs = \json_decode((string) \file_get_contents($root . '/last-ffmpeg-args.json'), true);

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video.mp4');
            self::assertStringContainsString('⏱️ Время работы:', $logs);
            self::assertIsArray($videoArgs);
            self::assertIsArray($audioArgs);
            self::assertIsArray($ffmpegArgs);
            self::assertContains('-hide_banner', $ffmpegArgs);
            self::assertSame('error', $ffmpegArgs[array_search('-loglevel', $ffmpegArgs, true) + 1]);
            self::assertSame('137', $videoArgs[array_search('-f', $videoArgs, true) + 1]);
            self::assertSame('251', $audioArgs[array_search('-f', $audioArgs, true) + 1]);
            self::assertSame('https://example.com/video', $videoArgs[array_key_last($videoArgs)]);
            self::assertSame('https://example.com/video', $audioArgs[array_key_last($audioArgs)]);
            self::assertContains('--newline', $videoArgs);
            self::assertContains('--continue', $videoArgs);
            self::assertContains('--continue', $audioArgs);
            self::assertSame('6', $videoArgs[array_search('--concurrent-fragments', $videoArgs, true) + 1]);
            self::assertNotContains('--load-info-json', $videoArgs);
            self::assertNotContains('--load-info-json', $audioArgs);
            self::assertNotContains('--extract-audio', $audioArgs);
            self::assertNotContains('--merge-output-format', $videoArgs);
            self::assertContains('-map', $ffmpegArgs);
            self::assertSame($downloadDir . '/My_Cool_Video.mp4', $ffmpegArgs[array_key_last($ffmpegArgs)]);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoFastRetriesHttp403StreamsWithResume(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_fast_retry_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        \file_put_contents($binDir . '/yt-dlp', FakeDownloaderBinaries::fastYtDlpWithTransientVideo403());
        \chmod($binDir . '/yt-dlp', 0777);
        \file_put_contents($binDir . '/ffmpeg', FakeDownloaderBinaries::ffmpeg());
        \chmod($binDir . '/ffmpeg', 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideoFast('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $logs = $output->fetch();

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video.mkv');
            self::assertSame('2', trim((string) \file_get_contents($root . '/stream-137-attempts.txt')));
            self::assertSame('1', trim((string) \file_get_contents($root . '/stream-140-attempts.txt')));
            self::assertFileExists($root . '/video-resume-seen.txt');
            self::assertStringContainsString('повторяю попытку 2/3 с докачкой', $logs);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoFastFallsBackToNormalDownloadWhenSeparateStreamsAreUnavailable(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_fast_fallback_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        \file_put_contents($binDir . '/yt-dlp', FakeDownloaderBinaries::ytDlp());
        \chmod($binDir . '/yt-dlp', 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $output = new BufferedOutput();
            $logger = new ConsoleLogger($output);
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideoFast('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());
            $logs = $output->fetch();

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video.mkv');
            self::assertStringContainsString('Не удалось подобрать отдельные video/audio потоки', $logs);
            self::assertSame(1, \substr_count($logs, '⏱️ Время работы:'));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

    public function testDownloadVideoFastFailsWhenFfmpegMergeFails(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_downloader_fast_ffmpeg_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        \file_put_contents($binDir . '/yt-dlp', FakeDownloaderBinaries::fastYtDlp());
        \chmod($binDir . '/yt-dlp', 0777);
        \file_put_contents($binDir . '/ffmpeg', FakeDownloaderBinaries::failingFfmpeg());
        \chmod($binDir . '/ffmpeg', 0777);

        $previousPath = \getenv('PATH');
        $previousDownloadDir = \getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(\getcwd() ?: null);
            $logger = new ConsoleLogger();
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideoFast('https://example.com/video', 'best', new \YtdPhp\Download\DownloadOptions());

            self::assertSame('failed', $result->status);
            self::assertFalse(\file_exists($downloadDir . '/My_Cool_Video.mkv'));
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $previousPath);
            }

            if ($previousDownloadDir === false) {
                putenv('DOWNLOAD_DIR_GENERAL');
            } else {
                putenv('DOWNLOAD_DIR_GENERAL=' . $previousDownloadDir);
            }
        }
    }

}
