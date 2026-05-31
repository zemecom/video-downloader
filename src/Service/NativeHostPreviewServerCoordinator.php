<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Bootstrap\RuntimeBootstrap;

use function fclose;
use function fwrite;
use function is_array;
use function is_resource;
use function proc_open;
use function stream_get_contents;
use function stream_set_timeout;
use function usleep;

final readonly class NativeHostPreviewServerCoordinator
{
    /**
     * @var Closure(): void
     */
    private Closure $starter;

    public function __construct(
        private RuntimeBootstrap $bootstrap,
        private NativeHostPreviewServerStateStore $stateStore,
        ?Closure $starter = null,
    ) {
        $this->starter = $starter ?? $this->makeDefaultStarter();
    }

    public function ensureRunning(): int
    {
        $existing = $this->stateStore->read();
        if (is_array($existing) && $this->isHealthy((int) ($existing['port'] ?? 0))) {
            return (int) $existing['port'];
        }

        ($this->starter)();

        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(100_000);
            $state = $this->stateStore->read();
            if (is_array($state) && $this->isHealthy((int) ($state['port'] ?? 0))) {
                return (int) $state['port'];
            }
        }

        throw new RuntimeException('Failed to start native preview server.');
    }

    private function isHealthy(int $port): bool
    {
        if ($port <= 0) {
            return false;
        }

        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.25);
        if (!is_resource($socket)) {
            return false;
        }

        try {
            stream_set_timeout($socket, 0, 250000);
            fwrite($socket, "HEAD /healthz HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            $statusLine = stream_get_contents($socket, 64);

            return is_string($statusLine) && str_contains($statusLine, '200');
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return Closure(): void
     */
    private function makeDefaultStarter(): Closure
    {
        return function (): void {
            $logPath = $this->bootstrap->getNativeHostPreviewServerLogPath();
            (new Filesystem())->mkdir(dirname($logPath));

            $process = proc_open(
                [
                    PHP_BINARY,
                    $this->bootstrap->getPackageRoot() . '/bin/ytd-native-preview-server',
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $logPath, 'a'],
                    2 => ['file', $logPath, 'a'],
                ],
                $pipes,
                $this->bootstrap->getPackageRoot(),
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Failed to start native preview server.');
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        };
    }
}
