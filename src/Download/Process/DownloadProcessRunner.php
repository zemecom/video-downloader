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

    private DownloadArtifactCleaner $artifactCleaner;

    public function __construct(
        private YtDlpGateway $ytDlpClient,
        private ConsoleLogger $logger,
        ?DownloadArtifactCleaner $artifactCleaner = null,
    ) {
        $this->artifactCleaner = $artifactCleaner ?? new DownloadArtifactCleaner();
    }

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
    public function runWithRetries(
        array $command,
        bool $emitLogs,
        ?string $sourceUrl = null,
        ?string $expectedFile = null,
    ): Process {
        $process = null;
        $currentCommand = $command;
        $hasCookiesInitially = $this->hasCookies($command);

        for ($attempt = 1; $attempt <= self::DOWNLOAD_MAX_ATTEMPTS; ++$attempt) {
            if ($attempt > 1) {
                $this->sleepBeforeRetry();
                $this->cleanupArtifactsBeforeRetry($expectedFile, $currentCommand);
                $currentCommand = $this->prepareNextAttemptCommand(
                    command: $command,
                    attempt: $attempt,
                    hasCookiesInitially: $hasCookiesInitially,
                    sourceUrl: $sourceUrl,
                );
            }

            $process = $emitLogs
                ? $this->runLiveDownloadWithProgress($currentCommand)
                : $this->ytDlpClient->runCaptured($currentCommand);

            if (!$this->shouldRetryDownloadProcess($process, $attempt)) {
                break;
            }

            if ($emitLogs) {
                $this->logRetryNotice($attempt + 1, $hasCookiesInitially);
            }
        }

        return $process;
    }

    /**
     * @param list<string> $command
     */
    public function hasCookies(array $command): bool
    {
        return \in_array('--cookies', $command, true) || \in_array('--cookies-from-browser', $command, true);
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    public function prepareNextAttemptCommand(
        array $command,
        int $attempt,
        bool $hasCookiesInitially,
        ?string $sourceUrl = null,
    ): array {
        if ($hasCookiesInitially) {
            return match ($attempt) {
                2 => $this->prepareNoCookiesCommand($command, $sourceUrl),
                default => $this->prepareVp9Command($command, $sourceUrl),
            };
        }

        return $this->prepareVp9Command($command, $sourceUrl);
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    public function prepareNoCookiesCommand(array $command, ?string $sourceUrl = null): array
    {
        $base = $this->prepareRetryCommand($command, $sourceUrl);
        $newCommand = [];
        $skipNext = false;

        foreach ($base as $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if ($arg === '--cookies' || $arg === '--cookies-from-browser') {
                $skipNext = true;
                continue;
            }
            $newCommand[] = $arg;
        }

        if (!\in_array('--no-cookies', $newCommand, true)) {
            $newCommand[] = '--no-cookies';
        }

        return $newCommand;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    public function prepareVp9Command(array $command, ?string $sourceUrl = null): array
    {
        $base = $this->prepareNoCookiesCommand($command, $sourceUrl);
        $newCommand = [];
        $skipNext = false;

        foreach ($base as $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if ($arg === '-S' || $arg === '--format-sort') {
                $skipNext = true;
                continue;
            }
            $newCommand[] = $arg;
        }

        $newCommand = $this->adjustFormatForVp9($newCommand);
        $newCommand[] = '-S';
        $newCommand[] = 'vcodec:vp9,res,fps';

        return $newCommand;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function adjustFormatForVp9(array $command): array
    {
        $count = \count($command);
        for ($i = 0; $i < $count - 1; ++$i) {
            if ($command[$i] === '-f') {
                $format = $command[$i + 1];
                if (\preg_match('/^\d+(?:\+\d+(?:-\d+)?)?$/', $format) === 1) {
                    $command[$i + 1] = 'bestvideo+bestaudio/best';
                }
                break;
            }
        }

        return $command;
    }

    /**
     * @param list<string> $command
     */
    private function cleanupArtifactsBeforeRetry(?string $expectedFile, array $command): void
    {
        $target = $expectedFile ?? $this->resolveOutputTargetFromCommand($command);
        if ($target !== null) {
            $this->artifactCleaner->cleanupFailedDownloadArtifacts($target);
        }
    }

    /**
     * @param list<string> $command
     */
    private function resolveOutputTargetFromCommand(array $command): ?string
    {
        $count = \count($command);
        for ($i = 0; $i < $count - 1; ++$i) {
            if ($command[$i] === '-o' && isset($command[$i + 1])) {
                return $command[$i + 1];
            }
        }

        return null;
    }

    private function logRetryNotice(int $nextAttempt, bool $hasCookiesInitially): void
    {
        if ($hasCookiesInitially) {
            if ($nextAttempt === 2) {
                $this->logger->warning('🔁 Ошибка HTTP 403 при скачивании с куками; повторяю попытку без куков...');

                return;
            }
            if ($nextAttempt === 3) {
                $this->logger->warning('🔁 Скачивание без куков не удалось; повторяю попытку в кодеке VP9...');

                return;
            }
        } else {
            if ($nextAttempt === 2) {
                $this->logger->warning('🔁 Ошибка HTTP 403 во время загрузки; повторяю попытку в кодеке VP9...');

                return;
            }
        }

        $this->logger->warning(\sprintf(
            '🔁 Повторяю попытку %d/%d со свежими ссылками...',
            $nextAttempt,
            self::DOWNLOAD_MAX_ATTEMPTS,
        ));
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
