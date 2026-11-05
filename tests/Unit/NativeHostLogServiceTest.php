<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\NativeHost\Log\NativeHostLogService;
use YtdPhp\Runtime\RuntimeBootstrap;

final class NativeHostLogServiceTest extends TestCase
{
    public function testAppendRotatesLogFileWhenExceedingMaxSize(): void
    {
        $root = sys_get_temp_dir() . '/ytd_php_log_test_' . uniqid();
        mkdir($root . '/logs', 0777, true);
        touch($root . '/composer.json');
        $logPath = $root . '/logs/native-host.log';

        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            // Создаем файл размером чуть больше 5МБ
            $fp = fopen($logPath, 'wb');
            self::assertIsResource($fp);
            fseek($fp, (5 * 1024 * 1024) + 10);
            fwrite($fp, 'end');
            fclose($fp);

            self::assertGreaterThan(5 * 1024 * 1024, filesize($logPath));

            $bootstrap = new RuntimeBootstrap($root);
            $logger = new NativeHostLogService($bootstrap);

            $logger->append('New log line after rotation');

            self::assertFileExists($logPath . '.1');
            self::assertFileExists($logPath);
            self::assertLessThan(1024, filesize($logPath));
            self::assertStringContainsString('New log line after rotation', (string) file_get_contents($logPath));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
