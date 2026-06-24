<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\NativeHost\NativeHostPreviewServerCoordinator;
use YtdPhp\NativeHost\NativeHostPreviewServerStateStore;

final class NativeHostPreviewServerCoordinatorTest extends TestCase
{
    public function testEnsureRunningRestartsWhenStatePointsToDeadProcess(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_preview_coordinator_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        $portFile = $root . '/health-port.txt';
        $serverScript = \sprintf(
            <<<'PHP'
$portFile = %s;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!is_resource($server)) {
    fwrite(STDERR, $error !== '' ? $error : 'bind failed');
    exit(1);
}
$address = (string) stream_socket_get_name($server, false);
$separator = strrpos($address, ':');
$port = $separator === false ? 0 : (int) substr($address, $separator + 1);
file_put_contents($portFile, (string) $port);
while (($client = @stream_socket_accept($server, -1)) !== false) {
    while (($line = fgets($client)) !== false && trim($line) !== '') {
    }
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
    fclose($client);
}
PHP,
            var_export($portFile, true),
        );

        $server = new Process([PHP_BINARY, '-r', $serverScript]);
        $server->start();

        try {
            $port = $this->waitForPort($portFile);
            $bootstrap = new RuntimeBootstrap($root);
            $stateStore = new NativeHostPreviewServerStateStore($bootstrap);
            $stateStore->write(999999, $port);

            $startCount = 0;
            $coordinator = new NativeHostPreviewServerCoordinator(
                $bootstrap,
                $stateStore,
                function () use (&$startCount, $stateStore, $port): void {
                    ++$startCount;
                    $stateStore->write(\getmypid(), $port);
                },
            );

            self::assertSame($port, $coordinator->ensureRunning());
            self::assertSame($port, $coordinator->ensureRunning());
            self::assertSame(1, $startCount);
        } finally {
            $server->stop(1);
            putenv('YTD_PROJECT_ROOT');
        }
    }

    private function waitForPort(string $portFile): int
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            if (\file_exists($portFile)) {
                $port = (int) \file_get_contents($portFile);
                if ($port > 0) {
                    return $port;
                }
            }

            \usleep(100_000);
        }

        self::fail('Timed out waiting for health server port.');
    }
}
