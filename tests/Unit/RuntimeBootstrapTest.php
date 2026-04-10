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
            ['bin/ytd', '--doctor', '--dry-run', '--no-proxy', '--no-playlist-sizes', '--concurrent-downloads', '3'],
            $bootstrap->normalizeArgv(['bin/ytd', '-dc', '-dr', '-np', '-nps', '-cd', '3']),
        );
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
}
