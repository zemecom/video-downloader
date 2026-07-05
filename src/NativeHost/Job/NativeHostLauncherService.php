<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Job;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use YtdPhp\Runtime\RuntimeBootstrap;

final readonly class NativeHostLauncherService
{
    /**
     * @var Closure(array<int, string>, string): void
     */
    private Closure $starter;

    public function __construct(
        private RuntimeBootstrap $bootstrap,
        ?Closure $starter = null,
    ) {
        $this->starter = $starter ?? $this->makeDefaultStarter();
    }

    public function launch(string $url): void
    {
        $command = [
            PHP_BINARY,
            $this->bootstrap->getPackageRoot() . '/bin/ytd',
            $url,
        ];

        ($this->starter)($command, $this->bootstrap->getNativeHostLogPath());
    }

    /**
     * @return Closure(array<int, string>, string): void
     */
    private function makeDefaultStarter(): Closure
    {
        return function (array $command, string $logPath): void {
            new Filesystem()->mkdir(\dirname($logPath));

            $process = \proc_open(
                $command,
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $logPath, 'a'],
                    2 => ['file', $logPath, 'a'],
                ],
                $pipes,
                $this->bootstrap->getPackageRoot(),
                null,
                ['create_new_console' => true],
            );

            if (!\is_resource($process)) {
                throw new RuntimeException('Failed to start native host command.');
            }

            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
        };
    }
}
