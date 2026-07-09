<?php

declare(strict_types=1);

namespace YtdPhp\Diagnostics;

use Symfony\Component\Process\Process;
use YtdPhp\Runtime\ProcessEnvironment;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Routing\RoutingConfigException;
use YtdPhp\Routing\RoutingService;
use YtdPhp\Shared\ConsoleLogger;

final readonly class DoctorService
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_WARN = 'warn';
    public const string STATUS_ERROR = 'error';

    private const string SKIP_BINARY_CHECKS_ENV = 'YTD_DOCTOR_SKIP_BINARY_CHECKS';

    private const string EXAMPLE_PROXY_REMOTE = 'http://user:pass@example.com:3128';
    private const string EXAMPLE_PROXY_LOCAL = 'http://127.0.0.1:8881';

    /** @var array<string, string> */
    private const array EXAMPLE_TEST_URLS = [
        'TEST_URL_DIRECT' => 'https://direct.example/video/123',
        'TEST_URL_REMOTE' => 'https://remote.example/video/123',
        'TEST_URL_LOCAL' => 'https://local.example/video/123',
        'TEST_PLAYLIST_LOCAL' => 'https://local.example/playlist/123',
    ];

    public function __construct(
        private RuntimeBootstrap $bootstrap,
        private RoutingService $routingService,
    ) {}

    /**
     * @return list<DoctorResult>
     */
    public function collectResults(?string $projectRoot = null): array
    {
        $root = $projectRoot ?? $this->bootstrap->getProjectRoot();
        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        $envValues = $this->bootstrap->readKeyValueFile($envPath);
        $rulesPath = $this->bootstrap->getProxyRulesPath();

        $results = $this->shouldSkipBinaryChecks()
            ? [
                new DoctorResult(
                    self::STATUS_WARN,
                    'Проверка yt-dlp пропущена',
                    'Smoke-режим doctor пропускает внешние бинарники.',
                ),
                new DoctorResult(
                    self::STATUS_WARN,
                    'Проверка ffmpeg пропущена',
                    'Smoke-режим doctor пропускает внешние бинарники.',
                ),
            ]
            : [
                $this->checkBinary(\getenv('YT_DLP_PATH') ?: 'yt-dlp', 'Установи yt-dlp и убедись, что команда доступна в PATH.'),
                $this->checkBinary('ffmpeg', 'Установи ffmpeg и убедись, что команда доступна в PATH.'),
            ];

        $results[] = \file_exists($envPath)
            ? new DoctorResult(self::STATUS_OK, '.env найден', $envPath)
            : new DoctorResult(self::STATUS_ERROR, '.env не найден', 'Создай его из .env.example: скопируй шаблон в .env.');

        $results[] = \file_exists($rulesPath)
            ? new DoctorResult(self::STATUS_OK, 'proxy_rules.yaml найден', $rulesPath)
            : new DoctorResult(self::STATUS_ERROR, 'proxy_rules.yaml не найден', 'Создай его из proxy_rules.example.yaml.');

        if ($envValues !== []) {
            if (($envValues['PROXY_LOCAL'] ?? '') === self::EXAMPLE_PROXY_LOCAL) {
                $results[] = new DoctorResult(
                    self::STATUS_WARN,
                    'PROXY_LOCAL выглядит как значение из шаблона',
                    'Если локальный прокси у тебя другой, замени значение в .env.',
                );
            }

            if (($envValues['PROXY_REMOTE'] ?? '') === self::EXAMPLE_PROXY_REMOTE) {
                $results[] = new DoctorResult(
                    self::STATUS_WARN,
                    'PROXY_REMOTE выглядит как шаблонное значение',
                    'Замени PROXY_REMOTE в .env на реальный удалённый прокси.',
                );
            }

            foreach (self::EXAMPLE_TEST_URLS as $key => $exampleValue) {
                if (($envValues[$key] ?? '') === $exampleValue) {
                    $results[] = new DoctorResult(
                        self::STATUS_WARN,
                        $key . ' всё ещё шаблонный',
                        'Это не мешает запуску, но для ручных integration-тестов лучше указать реальные ссылки.',
                    );
                }
            }
        }

        try {
            $routingConfig = $this->routingService->loadRoutingConfig($rulesPath);
            $results[] = new DoctorResult(self::STATUS_OK, 'Маршрутизация прокси загружается', $routingConfig->path);
            $hasGlobalFallback = array_any($routingConfig->orderedRules, fn($rule): bool => $rule->pattern === '*');
            if (!$hasGlobalFallback) {
                $results[] = new DoctorResult(
                    self::STATUS_WARN,
                    "В proxy_rules.yaml нет глобального fallback '*'",
                    "Некоторые домены могут не получить маршрут. Обычно удобно добавить '*' в один из разделов.",
                );
            }

            $localRules = $routingConfig->routing['local'] ?? [];
            $remoteRules = $routingConfig->routing['remote'] ?? [];
            if ($localRules !== [] && ($envValues['PROXY_LOCAL'] ?? '') === '') {
                $results[] = new DoctorResult(
                    self::STATUS_ERROR,
                    'Есть local-маршруты, но PROXY_LOCAL не задан',
                    'Заполни PROXY_LOCAL в .env или убери local-правила.',
                );
            }
            if ($remoteRules !== [] && ($envValues['PROXY_REMOTE'] ?? '') === '') {
                $results[] = new DoctorResult(
                    self::STATUS_ERROR,
                    'Есть remote-маршруты, но PROXY_REMOTE не задан',
                    'Заполни PROXY_REMOTE в .env или убери remote-правила.',
                );
            }
        } catch (RoutingConfigException $error) {
            $results[] = new DoctorResult(self::STATUS_ERROR, 'Не удалось прочитать proxy_rules.yaml', $error->getMessage());
        }

        return $results;
    }

    public function runDoctor(ConsoleLogger $logger, ?string $projectRoot = null): int
    {
        $results = $this->collectResults($projectRoot);
        $logger->line('🩺 Проверка окружения YTD');
        $logger->line('');
        foreach ($results as $result) {
            $logger->line($this->prefix($result->status) . ' ' . $result->title);
            $logger->line('   ' . $result->details);
        }

        $hasErrors = false;
        $hasWarnings = false;
        foreach ($results as $result) {
            $hasErrors = $hasErrors || $result->status === self::STATUS_ERROR;
            $hasWarnings = $hasWarnings || $result->status === self::STATUS_WARN;
        }

        $logger->line('');
        if ($hasErrors) {
            $logger->line('Итог: есть ошибки, которые нужно исправить перед первым запуском.');

            return 1;
        }

        if ($hasWarnings) {
            $logger->line('Итог: запускать уже можно, но есть вещи, которые стоит проверить.');

            return 0;
        }

        $logger->line('Итог: всё выглядит готовым к запуску.');

        return 0;
    }

    private function checkBinary(string $binary, string $installHint): DoctorResult
    {
        $process = new Process([$binary, '--version']);
        $process->setEnv(ProcessEnvironment::build());
        $process->run();
        if ($process->isSuccessful()) {
            $details = \trim($process->getOutput());

            return new DoctorResult(
                self::STATUS_OK,
                $binary . ' найден',
                $details !== '' ? $details : 'Команда отвечает на --version.',
            );
        }

        return new DoctorResult(self::STATUS_ERROR, $binary . ' не найден', $installHint);
    }

    private function prefix(string $status): string
    {
        return match ($status) {
            self::STATUS_OK => '✅',
            self::STATUS_WARN => '⚠️',
            default => '❌',
        };
    }

    private function shouldSkipBinaryChecks(): bool
    {
        $value = getenv(self::SKIP_BINARY_CHECKS_ENV);

        return \is_string($value) && $value === '1';
    }
}
