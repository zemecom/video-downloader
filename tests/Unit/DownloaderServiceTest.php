<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\ConsoleLogger;
use YtdPhp\Service\DownloaderService;
use YtdPhp\Service\InputPrompter;
use YtdPhp\Service\YtDlpClient;

use function chmod;
use function file_exists;
use function file_put_contents;
use function getcwd;
use function getenv;
use function mkdir;
use function sprintf;
use function str_replace;
use function sys_get_temp_dir;

final class DownloaderServiceTest extends TestCase
{
    public function testDownloadVideoReplacesSpacesWithUnderscoresInOutputFilename(): void
    {
        $root = sys_get_temp_dir() . '/ytd_php_downloader_' . uniqid();
        $binDir = $root . '/bin';
        $downloadDir = $root . '/downloads';
        mkdir($binDir, 0777, true);
        mkdir($downloadDir, 0777, true);

        $scriptPath = $binDir . '/yt-dlp';
        file_put_contents($scriptPath, $this->fakeYtDlpScript());
        chmod($scriptPath, 0777);

        $previousPath = getenv('PATH');
        $previousDownloadDir = getenv('DOWNLOAD_DIR_GENERAL');

        putenv('PATH=' . $binDir . PATH_SEPARATOR . ($previousPath !== false ? $previousPath : ''));
        putenv('DOWNLOAD_DIR_GENERAL=' . $downloadDir);

        try {
            $bootstrap = new RuntimeBootstrap(getcwd() ?: null);
            $logger = new ConsoleLogger();
            $prompter = new InputPrompter();
            $client = new YtDlpClient($logger);
            $service = new DownloaderService($client, $bootstrap, $logger, $prompter);

            $result = $service->downloadVideo('https://example.com/video', 'best');

            self::assertSame('completed', $result->status);
            self::assertFileExists($downloadDir . '/My_Cool_Video.mkv');
            self::assertFalse(file_exists($downloadDir . '/My Cool Video.mkv'));
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

    private function fakeYtDlpScript(): string
    {
        return <<<'PHP'
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
foreach ($args as $index => $arg) {
    if ($arg === '-o' && isset($args[$index + 1])) {
        $outputPath = $args[$index + 1];
        break;
    }
}

$resolveOutputPath = static function (?string $path) use ($metadata): string {
    $resolved = (string) $path;

    return str_replace(
        ['%(title)s', '%(id)s', '%(ext)s'],
        [
            (string) ($metadata['title'] ?? ''),
            (string) ($metadata['id'] ?? ''),
            (string) ($metadata['ext'] ?? 'mp4'),
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
PHP;
    }
}
