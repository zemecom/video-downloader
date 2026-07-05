<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Routing\RoutingService;

final class RoutingServiceTest extends TestCase
{
    public function testDirectSectionHasPriorityOverRemote(): void
    {
        $root = sys_get_temp_dir() . '/ytd_php_routing_' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/proxy_rules.yaml', <<<'YAML'
routing:
  direct:
    - "*.ru"
  local:
    - "*.youtube.com"
  remote:
    - "*"
YAML);

        putenv('YTD_PROJECT_ROOT=' . $root);
        putenv('PROXY_LOCAL=http://local:8080');
        putenv('PROXY_REMOTE=http://remote:9090');

        try {
            $routing = new RoutingService(new RuntimeBootstrap($root));
            $decision = $routing->resolveRoute('https://rutube.ru/video/123', null, false, false);

            self::assertSame('direct', $decision->mode);
            self::assertNull($decision->proxyUrl);
            self::assertSame('*.ru', $decision->matchedPattern);
        } finally {
            putenv('YTD_PROJECT_ROOT');
            putenv('PROXY_LOCAL');
            putenv('PROXY_REMOTE');
        }
    }

    public function testWildcardRuleMatchesRootDomain(): void
    {
        $routing = new RoutingService(new RuntimeBootstrap('/tmp/project'));

        self::assertTrue($routing->matchHostPattern('youtube.com', '*.youtube.com'));
        self::assertTrue($routing->matchHostPattern('m.youtube.com', '*.youtube.com'));
        self::assertFalse($routing->matchHostPattern('youtube.net', '*.youtube.com'));
    }
}
