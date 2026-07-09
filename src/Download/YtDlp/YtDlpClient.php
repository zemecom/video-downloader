<?php

declare(strict_types=1);

namespace YtdPhp\Download\YtDlp;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use YtdPhp\Runtime\ProcessEnvironment;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Shared\UserFacingException;

final readonly class YtDlpClient implements YtDlpGateway
{
    /**
     * @return array<string, string>
     */
    public static function buildProcessEnv(): array
    {
        return ProcessEnvironment::build();
    }

    public static function buildAugmentedPath(): string
    {
        return ProcessEnvironment::buildAugmentedPath();
    }

    public function __construct(
        private ConsoleLogger $logger,
    ) {}

    public function checkBinary(): void
    {
        $ytDlp = \getenv('YT_DLP_PATH') ?: 'yt-dlp';
        $process = new Process([$ytDlp, '--version']);
        $process->setEnv(ProcessEnvironment::build());
        $process->run();
        if ($process->isSuccessful()) {
            return;
        }

        $this->logger->error('🥺 Ошибка: `yt-dlp` не найден.');
        $this->logger->error('Пожалуйста, убедись, что он установлен и доступен в твоём PATH.');
        $this->logger->error('Установи его любым удобным способом для своей ОС.');
        $this->logger->error('Инструкции по установке: https://github.com/yt-dlp/yt-dlp#installation');

        throw new UserFacingException('yt-dlp не найден');
    }

    /**
     * @param list<string> $command
     * @return array<mixed>
     */
    public function runJson(array $command): array
    {
        $process = new Process($command);
        $process->setEnv(ProcessEnvironment::build());
        $process->mustRun();
        $output = \trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        $decoded = \json_decode($output, true);
        if (\is_array($decoded)) {
            return $decoded;
        }

        throw new UserFacingException('yt-dlp вернул неожиданный JSON.');
    }

    /**
     * @param list<string> $command
     */
    public function runLive(array $command, bool $passthrough = true): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->setEnv(ProcessEnvironment::build());
        $process->run(function (string $type, string $buffer) use ($passthrough): void {
            if ($passthrough) {
                $this->logger->raw($buffer);
            }
        });

        return $process;
    }

    /**
     * @param list<string> $command
     */
    public function runCaptured(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->setEnv(ProcessEnvironment::build());
        $process->run();

        return $process;
    }

    public function listFormats(string $videoUrl, ?string $proxy = null, bool $insecure = false): bool
    {
        $ytDlp = \getenv('YT_DLP_PATH') ?: 'yt-dlp';
        $command = [$ytDlp];
        if (\is_string($proxy) && $proxy !== '') {
            $command[] = $proxy;
        }
        if ($insecure) {
            $command[] = '--no-check-certificate';
        }
        $command[] = '--list-formats';
        $command[] = $videoUrl;

        $this->logger->info('✨ Получаю список форматов для: ' . $videoUrl);
        $this->logger->info(str_repeat('-', 30));
        $process = $this->runLive($command);
        if ($process->isSuccessful()) {
            $this->logger->info(str_repeat('-', 30));

            return true;
        }

        $this->logger->error('😭 Не удалось получить форматы. Ошибка:');

        return false;
    }

    public function getExpectedFilename(
        ?string $videoUrl,
        string $formatCode,
        string $outputPath,
        ?string $proxy = null,
        bool $insecure = false,
        ?string $infoJsonPath = null,
        string $outputFormat = 'mkv',
    ): ?string {
        $builder = new YtDlpCommandBuilder($videoUrl);
        if ($infoJsonPath === null) {
            $builder->setProxy($proxy);
        }

        $builder->setInsecure($insecure)->loadInfoJson($infoJsonPath);
        $command = $builder->buildForFilename($outputPath);
        if ($infoJsonPath !== null && $videoUrl !== null) {
            $command = \array_values(\array_filter($command, static fn(string $value): bool => $value !== $videoUrl));
        }

        $process = new Process($command);
        $process->setEnv(ProcessEnvironment::build());
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        $filename = \trim($process->getOutput());
        if ($filename === '') {
            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $extension !== '' ? substr($filename, 0, -strlen($extension) - 1) : $filename;

        if ($formatCode === 'bestaudio') {
            return $base . '.opus';
        }

        $willMerge = false;
        if ($infoJsonPath !== null && \file_exists($infoJsonPath)) {
            $payload = \json_decode((string) \file_get_contents($infoJsonPath), true);
            if (!\is_array($payload)) {
                $willMerge = true;
            } else {
                $requestedFormats = $payload['requested_formats'] ?? null;
                $willMerge = \is_array($requestedFormats) && count($requestedFormats) > 1;
            }
        }

        return $willMerge ? $base . '.' . $outputFormat : $filename;
    }

    public function getProcessErrorDetail(Process|ProcessFailedException $processOrException, string $fallback = 'command_failed'): string
    {
        $process = $processOrException instanceof ProcessFailedException
            ? $processOrException->getProcess()
            : $processOrException;

        $stderr = \trim($process->getErrorOutput());
        if ($stderr !== '') {
            return $stderr;
        }

        $stdout = \trim($process->getOutput());
        if ($stdout !== '') {
            return $stdout;
        }

        return $fallback;
    }
}
