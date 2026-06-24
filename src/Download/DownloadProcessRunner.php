<?php

declare(strict_types=1);

namespace YtdPhp\Download;

use LogicException;
use Symfony\Component\Process\Process;
use YtdPhp\Shared\ConsoleLogger;

final readonly class DownloadProcessRunner
{
    private const int DOWNLOAD_MAX_ATTEMPTS = 3;

    public function __construct(
        private YtDlpClient $ytDlpClient,
        private ConsoleLogger $logger,
    ) {}

    /**
     * @param list<string> $command
     */
    public function createProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->setEnv(YtDlpClient::buildProcessEnv());

        return $process;
    }

    /**
     * @param list<string> $command
     */
    public function runWithRetries(array $command, bool $emitLogs): Process
    {
        $process = null;

        for ($attempt = 1; $attempt <= self::DOWNLOAD_MAX_ATTEMPTS; ++$attempt) {
            $process = $emitLogs
                ? $this->runLiveDownloadWithProgress($command)
                : $this->ytDlpClient->runCaptured($command);

            if (!$this->shouldRetryDownloadProcess($process, $attempt)) {
                break;
            }

            if ($emitLogs) {
                $this->logger->warning(\sprintf(
                    '🔁 HTTP 403 во время загрузки; повторяю попытку %d/%d с докачкой.',
                    $attempt + 1,
                    self::DOWNLOAD_MAX_ATTEMPTS,
                ));
            }
        }

        if (!$process instanceof Process) {
            throw new LogicException('Download process was not initialized.');
        }

        return $process;
    }

    public function isRetryableHttp403Failure(Process $process): bool
    {
        $detail = $this->ytDlpClient->getProcessErrorDetail($process, '');

        return \str_contains($detail, 'HTTP Error 403') || \str_contains($detail, 'HTTP 403');
    }

    /**
     * @param list<string> $command
     */
    private function runLiveDownloadWithProgress(array $command): Process
    {
        $progress = new YtDlpProgressRenderer($this->logger, ['download']);
        $process = $this->createProcess($command);
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
