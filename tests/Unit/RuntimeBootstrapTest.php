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
}
