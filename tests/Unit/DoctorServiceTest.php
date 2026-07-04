<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Diagnostics\DoctorService;
use YtdPhp\Routing\RoutingService;

final class DoctorServiceTest extends TestCase
{
    public function testCollectResultsWarnsAboutPlaceholderProxy(): void
    {
        $root = sys_get_temp_dir() . '/ytd_php_doctor_' . uniqid();
        mkdir($root, 0777, true);
        file_put_contents($root . '/.env', "PROXY_LOCAL=http://127.0.0.1:8881\nPROXY_REMOTE=http://user:pass@example.com:3128\n");
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

        try {
            $service = new DoctorService(new RuntimeBootstrap($root), new RoutingService(new RuntimeBootstrap($root)));
            $results = $service->collectResults($root);
            $titles = array_map(static fn(\YtdPhp\Diagnostics\DoctorResult $result): string => $result->title, $results);

            self::assertContains('PROXY_LOCAL выглядит как значение из шаблона', $titles);
            self::assertContains('PROXY_REMOTE выглядит как шаблонное значение', $titles);
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
