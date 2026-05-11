<?php

declare(strict_types=1);

namespace YtdPhp\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Dto\RuntimeOptions;
use YtdPhp\Exception\RoutingConfigException;
use YtdPhp\Exception\UserFacingException;
use YtdPhp\Service\ConsoleLogger;
use YtdPhp\Service\DoctorService;
use YtdPhp\Service\PlaylistFlowService;
use YtdPhp\Service\PlaylistService;
use YtdPhp\Service\RoutingService;
use YtdPhp\Service\SingleVideoFlowService;
use YtdPhp\Service\YtDlpClient;

final class YtdCommand extends Command
{
    protected static $defaultName = 'ytd';

    public function __construct(
        private readonly RuntimeBootstrap $bootstrap,
        private readonly ConsoleLogger $logger,
        private readonly DoctorService $doctorService,
        private readonly RoutingService $routingService,
        private readonly YtDlpClient $ytDlpClient,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что будет скачано, но не запускать загрузку')
            ->addOption('mp4', null, InputOption::VALUE_NONE, 'Сохранить в формате MP4 (вместо MKV)')
            ->addOption('no-playlist-sizes', null, InputOption::VALUE_NONE, 'Показать плейлист без предварительного подсчёта размеров')
            ->addOption('concurrent-downloads', null, InputOption::VALUE_REQUIRED, 'Сколько роликов из плейлиста качать одновременно', '1')
            ->addOption('doctor', null, InputOption::VALUE_NONE, 'Проверить окружение и конфиги без скачивания');
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
        $outputFormat = (bool) $input->getOption('mp4') ? 'mp4' : $this->bootstrap->getDefaultOutputFormat();
        $currentProxy = $proxyUrl !== null ? '--proxy=' . $proxyUrl : null;

        return new RuntimeOptions(
            $proxyUrl,
            $currentProxy,
            (bool) $input->getOption('insecure'),
            (bool) $input->getOption('manual'),
            (bool) $input->getOption('audio'),
            (bool) $input->getOption('dry-run'),
            !(bool) $input->getOption('no-playlist-sizes'),
            max(1, (int) $input->getOption('concurrent-downloads')),
            $outputFormat,
            $route->mode,
            $route->matchedSection,
            $route->matchedPattern,
            $route->hostname,
        );
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
