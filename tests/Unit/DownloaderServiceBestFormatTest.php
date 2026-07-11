<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Download\DownloaderService;
use YtdPhp\Shared\InputPrompter;
use YtdPhp\Download\YtDlp\YtDlpClient;

final class DownloaderServiceBestFormatTest extends TestCase
{
    public function testDownloadVideoDoesNotUseYoutubeAv1FilterForNonYoutubeUrls(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_best_format_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, $this->fakeYtDlpScriptThatRejectsYoutubeOnlyBestFormat());
        \chmod($scriptPath, 0777);

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

            $result = $service->downloadVideo('https://www.xvideos.com/video.oufdtba54ef/example', 'best', new \YtdPhp\Download\DownloadOptions());

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video_1080p.mkv');
            self::assertFalse(\file_exists($downloadDir . '/My Cool Video.mkv'));
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

    public function testDownloadVideoFallsBackSafelyForMediumQualityOnNonYoutubeUrls(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_php_medium_format_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        \mkdir($binDir, 0777, true);
        \mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        \file_put_contents($scriptPath, $this->fakeYtDlpScriptThatRejectsYoutubeOnlyBestFormat());
        \chmod($scriptPath, 0777);

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

            $result = $service->downloadVideo('https://www.xvideos.com/video.oufdtba54ef/example', 'medium', new \YtdPhp\Download\DownloadOptions());

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video_1080p.mkv');
            self::assertFalse(\file_exists($downloadDir . '/My Cool Video.mkv'));
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

    private function fakeYtDlpScriptThatRejectsYoutubeOnlyBestFormat(): string
    {
        return <<<'PHP_WRAP'
        #!/usr/bin/env php
        <?php
        
        $args = $argv;
        array_shift($args);
        
        $metadata = [
            'title' => 'My Cool Video',
            'id' => 'abc 123',
            'ext' => 'webm',
            'requested_formats' => [
                ['format_id' => '137'],
                ['format_id' => '251'],
            ],
        ];
        
        foreach ($args as $index => $arg) {
            if ($arg === '--load-info-json' && isset($args[$index + 1]) && is_file($args[$index + 1])) {
                $loaded = json_decode((string) file_get_contents($args[$index + 1]), true);
                if (is_array($loaded)) {
                    $metadata = array_replace($metadata, $loaded);
                }
            }
        }
        
        $outputPath = null;
        $formatCode = null;
        foreach ($args as $index => $arg) {
            if ($arg === '-o' && isset($args[$index + 1])) {
                $outputPath = $args[$index + 1];
            }
            if ($arg === '-f' && isset($args[$index + 1])) {
                $formatCode = $args[$index + 1];
            }
        }
        
        $resolveOutputPath = static function (?string $path) use ($metadata): string {
            $resolved = (string) $path;
        
            return str_replace(
                ['%(title)s', '%(id)s', '%(ext)s', '%(resolution)s'],
                [
                    (string) ($metadata['title'] ?? ''),
                    (string) ($metadata['id'] ?? ''),
                    (string) ($metadata['ext'] ?? 'mp4'),
                    (string) ($metadata['resolution'] ?? '1080p'),
                ],
                $resolved,
            );
        };
        
        if (in_array('--dump-json', $args, true)) {
            fwrite(STDOUT, json_encode($metadata, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        
            exit(0);
        }
        
        if (in_array('--get-filename', $args, true)) {
            fwrite(STDOUT, $resolveOutputPath($outputPath) . PHP_EOL);
        
            exit(0);
        }
        
        if (is_string($formatCode) && str_contains($formatCode, 'vcodec!^=av01')) {
            fwrite(STDERR, "ERROR: Requested format is not available\n");
        
            exit(1);
        }
        
        if (!is_string($outputPath) || $outputPath === '') {
            fwrite(STDERR, "missing output path\n");
        
            exit(1);
        }
        
        $resolvedPath = $resolveOutputPath($outputPath);
        $directory = dirname($resolvedPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($resolvedPath, 'video-bytes');
        
        exit(0);
        PHP_WRAP;
    }
}
