<?php

declare(strict_types=1);

namespace YtdPhp\Download\Process;

use YtdPhp\Download\YtDlp\YtDlpGateway;
use YtdPhp\Download\YtDlp\YtDlpProgressRenderer;
use LogicException;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\ProcessEnvironment;
use YtdPhp\Shared\ConsoleLogger;

final readonly class DownloadProcessRunner
{
    private const int DOWNLOAD_MAX_ATTEMPTS = 3;

    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private ConsoleLogger $logger,
    ) {}

    /**
     * @param list<string> $command
     */
    public function createProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->setEnv(ProcessEnvironment::build());

        return $process;
    }

    /**
     * @param list<string> $command
     */
    public function runWithRetries(array $command, bool $emitLogs, ?string $sourceUrl = null): Process
    {
        $process = null;
        $currentCommand = $command;

        for ($attempt = 1; $attempt <= self::DOWNLOAD_MAX_ATTEMPTS; ++$attempt) {
            if ($attempt > 1) {
                $currentCommand = $this->prepareRetryCommand($command, $sourceUrl);
                $this->sleepBeforeRetry();
            }

            $process = $emitLogs
                ? $this->runLiveDownloadWithProgress($currentCommand)
                : $this->ytDlpClient->runCaptured($currentCommand);

            if (!$this->shouldRetryDownloadProcess($process, $attempt)) {
                break;
            }

            if ($emitLogs) {
                $this->logger->warning(\sprintf(
                    '🔁 HTTP 403 во время загрузки; повторяю попытку %d/%d с докачкой (со свежими ссылками).',
                    $attempt + 1,
                    self::DOWNLOAD_MAX_ATTEMPTS,
                ));
            }
        }

        return $process;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    public function prepareRetryCommand(array $command, ?string $sourceUrl = null): array
    {
        $newCommand = [];
        $skipNext = false;
        foreach ($command as $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if ($arg === '--load-info-json') {
                $skipNext = true;
                continue;
            }
            $newCommand[] = $arg;
        }

        if (!\in_array('--no-cache-dir', $newCommand, true)) {
            $newCommand[] = '--no-cache-dir';
        }

        if (\is_string($sourceUrl) && $sourceUrl !== '' && !\in_array($sourceUrl, $newCommand, true)) {
            $newCommand[] = $sourceUrl;
        }

        return $newCommand;
    }

    private function sleepBeforeRetry(): void
    {
        if (\getenv('APP_ENV') === 'test' || \getenv('YTD_TEST_NO_SLEEP') === '1') {
            return;
        }

        \sleep(1);
    }

    public function isRetryableHttp403Failure(Process $process): bool
    {
        $detail = $this->ytDlpClient->getProcessErrorDetail($process, '');

        return \str_contains($detail, 'HTTP Error 403') || \str_contains($detail, 'HTTP 403');
    }

    private function runLiveDownloadWithProgress(array $command): Process
    {
        $process = $this->createProcess($command);

        if (\getenv('YTD_RAW_PROGRESS') === '1') {
            $process->run(function (string $type, string $buffer): void {
                $this->logger->raw($buffer);
            });

            return $process;
        }

        $progress = new YtDlpProgressRenderer($this->logger, ['download']);
        $process->run(function (string $type, string $buffer) use ($progress): void {
            $progress->consume('download', $buffer);
        });
        $progress->finish();

        return $process;
    }

    private function shouldRetryDownloadProcess(Process $process, int $attempt): bool
    {
        if ($process->isSuccessful() || $attempt >= self::DOWNLOAD_MAX_ATTEMPTS) {
            return false;
        }

        return $this->isRetryableHttp403Failure($process);
    }
}
