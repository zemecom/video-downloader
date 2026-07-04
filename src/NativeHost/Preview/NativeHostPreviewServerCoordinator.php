<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Preview;

use YtdPhp\NativeHost\Store\NativeHostPreviewServerStateStore;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Runtime\RuntimeBootstrap;

use const LOCK_EX;
use const LOCK_UN;

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
        return $this->withStartupLock(function (): int {
            $existing = $this->stateStore->read();
            if ($this->isOwnedHealthyState($existing)) {
                return (int) $existing['port'];
            }

            ($this->starter)();

            for ($attempt = 0; $attempt < 20; $attempt++) {
                \usleep(100_000);
                $state = $this->stateStore->read();
                if ($this->isOwnedHealthyState($state)) {
                    return (int) $state['port'];
                }
            }

            throw new RuntimeException('Failed to start native preview server.');
        });
    }

    private function isHealthy(int $port): bool
    {
        if ($port <= 0) {
            return false;
        }

        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.25);
        if (!\is_resource($socket)) {
            return false;
        }

        try {
            \stream_set_timeout($socket, 0, 250000);
            \fwrite($socket, "HEAD /healthz HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            $statusLine = \stream_get_contents($socket, 64);

            return is_string($statusLine) && str_contains($statusLine, '200');
        } finally {
            \fclose($socket);
        }
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function isOwnedHealthyState(?array $state): bool
    {
        if (!\is_array($state)) {
            return false;
        }

        $pid = (int) ($state['pid'] ?? 0);
        $port = (int) ($state['port'] ?? 0);

        return $this->isProcessAlive($pid) && $this->isHealthy($port);
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (\function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $process = new Process(['kill', '-0', (string) $pid]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function withStartupLock(Closure $callback): mixed
    {
        $lockPath = $this->bootstrap->getNativeHostPreviewServerStatePath() . '.startup.lock';
        (new Filesystem())->mkdir(\dirname($lockPath));

        $handle = \fopen($lockPath, 'c+');
        if (!\is_resource($handle)) {
            return $callback();
        }

        try {
            if (!\flock($handle, LOCK_EX)) {
                return $callback();
            }

            return $callback();
        } finally {
            \flock($handle, LOCK_UN);
            \fclose($handle);
        }
    }

    /**
     * @return Closure(): void
     */
    private function makeDefaultStarter(): Closure
    {
        return function (): void {
            $logPath = $this->bootstrap->getNativeHostPreviewServerLogPath();
            (new Filesystem())->mkdir(\dirname($logPath));

            $process = \proc_open(
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

            if (!\is_resource($process)) {
                throw new RuntimeException('Failed to start native preview server.');
            }

            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
        };
    }
}
