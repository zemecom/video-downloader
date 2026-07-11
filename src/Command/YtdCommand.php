<?php

declare(strict_types=1);

namespace YtdPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Runtime\RuntimeOptions;
use YtdPhp\Routing\RoutingConfigException;
use YtdPhp\Shared\UserFacingException;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Diagnostics\DoctorService;
use YtdPhp\Playlist\PlaylistFlowService;
use YtdPhp\Playlist\PlaylistService;
use YtdPhp\Routing\RoutingService;
use YtdPhp\Download\SingleVideoFlowService;
use YtdPhp\Download\YtDlp\YtDlpGateway;

final class YtdCommand extends Command
{
    private static string $defaultName = 'ytd';

    public function __construct(
        private readonly RuntimeBootstrap $bootstrap,
        private readonly ConsoleLogger $logger,
        private readonly DoctorService $doctorService,
        private readonly RoutingService $routingService,
        private readonly YtDlpGateway $ytDlpClient,
        private readonly PlaylistService $playlistService,
        private readonly SingleVideoFlowService $singleVideoFlowService,
        private readonly PlaylistFlowService $playlistFlowService,
    ) {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('CLI для скачивания видео и плейлистов через yt-dlp с маршрутизацией прокси.')
            ->setHelp('(По умолчанию используется локальный прокси: ' . $this->bootstrap->getProxyEpilog() . ')')
            ->addArgument('url', InputArgument::OPTIONAL, 'Ссылка на видео (YouTube или др.)')
            ->addOption('proxy', null, InputOption::VALUE_REQUIRED, 'Явно указать прокси (перекрывает другие настройки)')
            ->addOption('no-proxy', null, InputOption::VALUE_NONE, 'Отключить прокси')
            ->addOption('remote', 'r', InputOption::VALUE_NONE, 'Использовать удалённый прокси')
            ->addOption('insecure', 'i', InputOption::VALUE_NONE, 'Отключить проверку SSL сертификатов')
            ->addOption('manual', 'm', InputOption::VALUE_NONE, 'Ручной режим (выбор формата)')
            ->addOption('audio', 'a', InputOption::VALUE_NONE, 'Скачать только аудио в лучшем формате (opus)')
            ->addOption('fast', null, InputOption::VALUE_NONE, 'Скачать видео и аудио параллельно, затем объединить через ffmpeg')
            ->addOption('quality', 'Q', InputOption::VALUE_REQUIRED, 'Качество видео: b/best, f/fhd, m/medium, l/low', 'b')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что будет скачано, но не запускать загрузку')
            ->addOption('mp4', null, InputOption::VALUE_NONE, 'Сохранить в формате MP4 (вместо MKV)')
            ->addOption('output-format', null, InputOption::VALUE_REQUIRED, 'Итоговый контейнер: mkv или mp4', $this->bootstrap->getDefaultOutputFormat())
            ->addOption('download-dir', null, InputOption::VALUE_REQUIRED, 'Папка назначения для текущего запуска')
            ->addOption('no-playlist-sizes', null, InputOption::VALUE_NONE, 'Показать плейлист без предварительного подсчёта размеров')
            ->addOption('concurrent-downloads', null, InputOption::VALUE_REQUIRED, 'Сколько роликов из плейлиста качать одновременно', (string) $this->bootstrap->getConcurrentDownloads())
            ->addOption('concurrent-fragments', null, InputOption::VALUE_REQUIRED, 'Сколько фрагментов одного файла качать параллельно через yt-dlp', (string) $this->bootstrap->getConcurrentFragments())
            ->addOption('progress-newline', null, InputOption::VALUE_NEGATABLE, 'Печатать прогресс построчно вместо перерисовки')
            ->addOption('progress-delta', null, InputOption::VALUE_REQUIRED, 'Интервал обновления прогресса yt-dlp в секундах', $this->bootstrap->getProgressDelta())
            ->addOption('doctor', null, InputOption::VALUE_NONE, 'Проверить окружение и конфиги без скачивания')
            ->addOption('4k', '4', InputOption::VALUE_NONE, 'Игнорировать ограничения кодеков и качать в максимальном 4K/8K разрешении');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->setOutput($output);

        try {
            if ((bool) $input->getOption('doctor')) {
                return $this->doctorService->runDoctor($this->logger);
            }

            $videoUrl = $this->requireVideoUrl($input);
            $options = $this->buildRuntimeOptions($input, $videoUrl);
            $this->logRuntimeConfiguration($options);
            $this->ytDlpClient->checkBinary();

            if ($this->playlistService->shouldTreatAsPlaylist($videoUrl, $options)) {
                if ($options->fastMode) {
                    throw new UserFacingException('`--fast` пока поддерживается только для одиночных видео.');
                }

                return $this->playlistFlowService->handle($videoUrl, $options);
            }

            return $this->singleVideoFlowService->handle($videoUrl, $options);
        } catch (RoutingConfigException|UserFacingException $error) {
            $this->logger->error($error->getMessage());

            return Command::FAILURE;
        }
    }

    private function buildRuntimeOptions(InputInterface $input, string $videoUrl): RuntimeOptions
    {
        $route = $this->routingService->resolveRoute(
            $videoUrl,
            $this->optionalString($input->getOption('proxy')),
            (bool) $input->getOption('no-proxy'),
            (bool) $input->getOption('remote'),
        );
        $proxyUrl = $route->proxyUrl;
        $outputFormat = $this->resolveOutputFormat($input);
        $currentProxy = $proxyUrl !== null ? '--proxy=' . $proxyUrl : null;
        $progressNewline = $this->resolveProgressNewlineOverride($input);
        $manualMode = (bool) $input->getOption('manual');
        $audioOnly = (bool) $input->getOption('audio');
        $fastMode = (bool) $input->getOption('fast');
        $this->validateFastMode($fastMode, $manualMode, $audioOnly);

        return new RuntimeOptions(
            $proxyUrl,
            $currentProxy,
            (bool) $input->getOption('insecure'),
            $manualMode,
            $audioOnly,
            $fastMode,
            $this->resolveQualityPreset($input),
            (bool) $input->getOption('dry-run'),
            !(bool) $input->getOption('no-playlist-sizes'),
            max(1, (int) $input->getOption('concurrent-downloads')),
            max(1, (int) $input->getOption('concurrent-fragments')),
            $this->optionalString($input->getOption('download-dir')),
            $progressNewline,
            $this->resolveProgressDelta($input),
            $outputFormat,
            $route->mode,
            $route->matchedSection,
            $route->matchedPattern,
            $route->hostname,
            (bool) $input->getOption('4k'),
        );
    }

    private function validateFastMode(bool $fastMode, bool $manualMode, bool $audioOnly): void
    {
        if (!$fastMode) {
            return;
        }

        if ($audioOnly) {
            throw new UserFacingException('`--fast` нельзя использовать вместе с `--audio`: аудио-режим уже скачивает только один поток.');
        }

        if ($manualMode) {
            throw new UserFacingException('`--fast` пока не поддерживает `--manual`: быстрый режим сам выбирает пару video/audio потоков.');
        }
    }

    private function resolveOutputFormat(InputInterface $input): string
    {
        if ((bool) $input->getOption('mp4')) {
            return 'mp4';
        }

        $value = $this->optionalString($input->getOption('output-format')) ?? $this->bootstrap->getDefaultOutputFormat();
        $normalized = $this->bootstrap->normalizeOutputFormat($value);
        if ($normalized !== strtolower($value)) {
            throw new UserFacingException('Неподдерживаемый output format. Используй mkv или mp4.');
        }

        return $normalized;
    }

    private function resolveProgressDelta(InputInterface $input): string
    {
        $value = $this->optionalString($input->getOption('progress-delta')) ?? $this->bootstrap->getProgressDelta();
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new UserFacingException('`--progress-delta` должен быть положительным числом.');
        }

        return $value;
    }

    private function resolveProgressNewlineOverride(InputInterface $input): ?bool
    {
        $value = $input->getOption('progress-newline');

        return is_bool($value) ? $value : null;
    }

    private function resolveQualityPreset(InputInterface $input): string
    {
        $value = strtolower($this->optionalString($input->getOption('quality')) ?? 'b');

        return match ($value) {
            'b', 'best' => 'best',
            'f', 'fhd' => 'fhd',
            'm', 'medium' => 'medium',
            'l', 'low' => 'low',
            default => throw new UserFacingException('`--quality` поддерживает только b/best, f/fhd, m/medium или l/low.'),
        };
    }

    private function logRuntimeConfiguration(RuntimeOptions $options): void
    {
        $matchedPattern = $options->matchedPattern ?? 'n/a';
        $this->logger->info(
            sprintf(
                '🧭 Маршрут: %s для %s (section: %s, matched: %s)',
                $options->routeMode,
                $options->hostname,
                $options->matchedSection,
                $matchedPattern,
            ),
        );

        if ($options->proxyUrl !== null) {
            $this->logger->info('🌍 Использую прокси: ' . $this->bootstrap->formatProxyForDisplay($options->proxyUrl));
        } else {
            $this->logger->info('🌍 Прокси отключен');
        }

        if ($options->insecure) {
            $this->logger->warning('⚠️ SSL проверка отключена');
        }
    }

    private function requireVideoUrl(InputInterface $input): string
    {
        $videoUrl = (string) ($input->getArgument('url') ?? '');
        if ($videoUrl !== '') {
            return $videoUrl;
        }

        $this->logger->error('Не указана ссылка на видео.');
        $this->logger->line($this->getDescription());

        throw new UserFacingException('URL не указан');
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
