<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;

final class RuntimeBootstrapTest extends TestCase
{
    public function testNormalizeArgvRewritesLegacyMultiLetterShortFlags(): void
    {
        $bootstrap = new RuntimeBootstrap('/tmp/project');

        self::assertSame(
            ['bin/ytd', '--doctor', '--dry-run', '--no-proxy', '--no-playlist-sizes', '--concurrent-downloads', '3', '--concurrent-fragments', '12'],
            $bootstrap->normalizeArgv(['bin/ytd', '-dc', '-dr', '-np', '-nps', '-cd', '3', '-cf', '12']),
        );
    }

    public function testConcurrentFragmentsUsesEnvValueAndClampsToAtLeastOne(): void
    {
        putenv('CONCURRENT_FRAGMENTS=0');

        try {
            $bootstrap = new RuntimeBootstrap('/tmp/project');
            self::assertSame(1, $bootstrap->getConcurrentFragments());
        } finally {
            putenv('CONCURRENT_FRAGMENTS');
        }

        putenv('CONCURRENT_FRAGMENTS=14');

        try {
            $bootstrap = new RuntimeBootstrap('/tmp/project');
            self::assertSame(14, $bootstrap->getConcurrentFragments());
        } finally {
            putenv('CONCURRENT_FRAGMENTS');
        }
    }

    public function testConcurrentDownloadsUsesEnvValueAndClampsToAtLeastOne(): void
    {
        putenv('CONCURRENT_DOWNLOADS=0');

        try {
            $bootstrap = new RuntimeBootstrap('/tmp/project');
            self::assertSame(1, $bootstrap->getConcurrentDownloads());
        } finally {
            putenv('CONCURRENT_DOWNLOADS');
        }

        putenv('CONCURRENT_DOWNLOADS=6');

        try {
            $bootstrap = new RuntimeBootstrap('/tmp/project');
            self::assertSame(6, $bootstrap->getConcurrentDownloads());
        } finally {
            putenv('CONCURRENT_DOWNLOADS');
        }
    }

    public function testProgressSettingsReadFromEnvironment(): void
    {
        putenv('YTD_PROGRESS_DELTA=1.25');
        putenv('YTD_PROGRESS_NEWLINE=yes');

        try {
            $bootstrap = new RuntimeBootstrap('/tmp/project');
            self::assertSame('1.25', $bootstrap->getProgressDelta());
            self::assertTrue($bootstrap->shouldUseProgressNewline());
        } finally {
            putenv('YTD_PROGRESS_DELTA');
            putenv('YTD_PROGRESS_NEWLINE');
        }
    }

    public function testProgressSettingsFallBackToDefaultsForInvalidValues(): void
    {
        putenv('YTD_PROGRESS_DELTA=abc');
        putenv('YTD_PROGRESS_NEWLINE=0');

        try {
            $bootstrap = new RuntimeBootstrap('/tmp/project');
            self::assertSame('0.5', $bootstrap->getProgressDelta());
            self::assertFalse($bootstrap->shouldUseProgressNewline());
        } finally {
            putenv('YTD_PROGRESS_DELTA');
            putenv('YTD_PROGRESS_NEWLINE');
        }
    }

    public function testProxyRulesPathResolvesRelativePathFromProjectRoot(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ytd_php_bootstrap_' . uniqid();
        mkdir($projectRoot, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $projectRoot);
        putenv('PROXY_RULES_FILE=config/rules.yaml');

        try {
            $bootstrap = new RuntimeBootstrap($projectRoot);
            self::assertStringEndsWith('/config/rules.yaml', $bootstrap->getProxyRulesPath());
        } finally {
            putenv('YTD_PROJECT_ROOT');
            putenv('PROXY_RULES_FILE');
        }
    }

    public function testErrorLogPathResolvesRelativePathFromProjectRoot(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ytd_php_error_log_' . uniqid();
        mkdir($projectRoot, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $projectRoot);
        putenv('YTD_ERROR_LOG_FILE=var/custom-errors.log');

        try {
            $bootstrap = new RuntimeBootstrap($projectRoot);
            self::assertStringEndsWith('/var/custom-errors.log', $bootstrap->getErrorLogPath());
        } finally {
            putenv('YTD_PROJECT_ROOT');
            putenv('YTD_ERROR_LOG_FILE');
        }
    }

    public function testErrorLogPathKeepsAbsoluteConfiguredPath(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ytd_php_error_log_abs_' . uniqid();
        mkdir($projectRoot, 0777, true);
        $absolutePath = $projectRoot . '/logs/errors.log';

        putenv('YTD_PROJECT_ROOT=' . $projectRoot);
        putenv('YTD_ERROR_LOG_FILE=' . $absolutePath);

        try {
            $bootstrap = new RuntimeBootstrap($projectRoot);
            self::assertSame($absolutePath, $bootstrap->getErrorLogPath());
        } finally {
            putenv('YTD_PROJECT_ROOT');
            putenv('YTD_ERROR_LOG_FILE');
        }
    }

    public function testNativeHostLogPathResolvesRelativePathFromProjectRoot(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ytd_php_native_host_log_' . uniqid();
        mkdir($projectRoot, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $projectRoot);

        try {
            $bootstrap = new RuntimeBootstrap($projectRoot);
            self::assertStringEndsWith('/logs/native-host.log', $bootstrap->getNativeHostLogPath());
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testNativeHostJobsDirectoryResolvesRelativePathFromProjectRoot(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ytd_php_native_host_jobs_' . uniqid();
        mkdir($projectRoot, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $projectRoot);

        try {
            $bootstrap = new RuntimeBootstrap($projectRoot);
            self::assertStringEndsWith('/logs/native-host-jobs', $bootstrap->getNativeHostJobsDirectoryPath());
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testLoadEnvFileParsesQuotedValuesAndComments(): void
    {
        $projectRoot = sys_get_temp_dir() . '/ytd_php_env_' . uniqid();
        mkdir($projectRoot, 0777, true);
        file_put_contents(
            $projectRoot . '/.env',
            <<<'ENV'
PROXY_REMOTE="http://user:pass@example.com:3128"
DOWNLOAD_DIR_GENERAL="~/Movies/Downloaded" # inline comment
ENV,
        );

        putenv('YTD_PROJECT_ROOT=' . $projectRoot);
        putenv('PROXY_REMOTE');
        putenv('DOWNLOAD_DIR_GENERAL');

        try {
            $bootstrap = new RuntimeBootstrap($projectRoot);
            $bootstrap->loadEnvFile('.env');

            self::assertSame('http://user:pass@example.com:3128', getenv('PROXY_REMOTE'));
            self::assertSame('~/Movies/Downloaded', getenv('DOWNLOAD_DIR_GENERAL'));
        } finally {
            putenv('YTD_PROJECT_ROOT');
            putenv('PROXY_REMOTE');
            putenv('DOWNLOAD_DIR_GENERAL');
        }
    }

    public function testIsYoutubeUrlMatchesYoutubeHostsOnly(): void
    {
        $bootstrap = new RuntimeBootstrap('/tmp/project');

        self::assertTrue($bootstrap->isYoutubeUrl('https://m.youtube.com/watch?v=abc123'));
        self::assertTrue($bootstrap->isYoutubeUrl('youtu.be/abc123'));
        self::assertFalse($bootstrap->isYoutubeUrl('https://notyoutube.com/watch?v=abc123'));
        self::assertFalse($bootstrap->isYoutubeUrl('https://example.com/youtu.be/abc123'));
    }

    public function testSanitizeOutputFilenameReplacesWhitespaceOnlyInFileName(): void
    {
        $bootstrap = new RuntimeBootstrap('/tmp/project');

        self::assertSame(
            '/tmp/My Dir/My_Cool_Video_[abc_123].mkv',
            $bootstrap->sanitizeOutputFilename('/tmp/My Dir/My Cool Video [abc 123].mkv'),
        );
        self::assertSame(
            '/tmp/My Dir/My_Cool_Video_[abc_123].mkv',
            $bootstrap->sanitizeOutputFilename('/tmp/My Dir/My  Cool Video [abc  123].mkv'),
        );
        self::assertSame(
            '/tmp/My Dir/Already__Separated_Title.mkv',
            $bootstrap->sanitizeOutputFilename('/tmp/My Dir/Already__Separated Title.mkv'),
        );
    }

    public function testSanitizeOutputFilenamePreservesPunctuationAndReplacesOnlyUnicodeWhitespace(): void
    {
        $bootstrap = new RuntimeBootstrap('/tmp/project');

        self::assertSame(
            '/tmp/Абстрактный：_Тестовый_сюжет_до_ноября_или_еще_2-3_года？.opus',
            $bootstrap->sanitizeOutputFilename("/tmp/Абстрактныи\u{0306}：\u{00A0}Тестовыи\u{0306}\u{202F}сюжет\u{200B}до_ноября_или_еще_2-3_года？.opus"),
        );
    }
}
