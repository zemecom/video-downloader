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
use YtdPhp\Service\DownloaderService;
use YtdPhp\Service\InputPrompter;
use YtdPhp\Service\PlaylistService;
use YtdPhp\Service\RoutingService;
use YtdPhp\Service\YtDlpClient;

final class YtdCommand extends Command
{
    protected static $defaultName = 'ytd';

    public function __construct(
        private readonly RuntimeBootstrap $bootstrap,
        private readonly ConsoleLogger $logger,
        private readonly InputPrompter $prompter,
        private readonly DoctorService $doctorService,
        private readonly RoutingService $routingService,
        private readonly YtDlpClient $ytDlpClient,
        private readonly DownloaderService $downloaderService,
        private readonly PlaylistService $playlistService,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, что будет скачано, но не запускать загрузку')
            ->addOption('mp4', null, InputOption::VALUE_NONE, 'Сохранить в формате MP4 (вместо MKV)')
            ->addOption('no-playlist-sizes', null, InputOption::VALUE_NONE, 'Показать плейлист без предварительного подсчёта размеров')
            ->addOption('concurrent-downloads', null, InputOption::VALUE_REQUIRED, 'Сколько роликов из плейлиста качать одновременно', '1')
            ->addOption('doctor', null, InputOption::VALUE_NONE, 'Проверить окружение и конфиги без скачивания');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->setOutput($output);

        if ((bool) $input->getOption('doctor')) {
            return $this->doctorService->runDoctor($this->logger);
        }

        $videoUrl = (string) ($input->getArgument('url') ?? '');
        if ($videoUrl === '') {
            $this->logger->error('Не указана ссылка на видео.');
            $this->logger->line($this->getDescription());

            return Command::FAILURE;
        }

        try {
            $options = $this->buildRuntimeOptions($input, $videoUrl);
            $this->logRuntimeConfiguration($options);
            $this->ytDlpClient->checkBinary();

            if ($this->playlistService->shouldTreatAsPlaylist($videoUrl, $options)) {
                if ($options->manualMode) {
                    $this->logger->error('Ручной режим пока не поддерживается для плейлистов.');

                    return Command::FAILURE;
                }

                $summary = $this->playlistService->fetchAndPreparePlaylist($videoUrl, $options);
                if ($summary === null) {
                    return Command::FAILURE;
                }

                $this->playlistService->printPlaylistSummary($summary);
                if ($options->dryRun) {
                    $this->playlistService->printPlaylistDryRun($summary);
                    $this->playlistService->cleanupPlaylistSummary($summary);

                    return $summary->preflightErrorCount === 0 ? Command::SUCCESS : Command::FAILURE;
                }

                if (!$this->playlistService->promptStorageConfirmation($summary)) {
                    $this->playlistService->cleanupPlaylistSummary($summary);
                    $this->logger->info('⏭️ Загрузка отменена.');

                    return Command::FAILURE;
                }

                $overwritePolicy = $this->playlistService->promptOverwritePolicy($summary);
                if ($overwritePolicy === PlaylistService::OVERWRITE_CANCEL) {
                    $this->playlistService->cleanupPlaylistSummary($summary);
                    $this->logger->info('⏭️ Загрузка отменена.');

                    return Command::FAILURE;
                }

                return $this->playlistService->downloadPlaylist($summary, $options, $overwritePolicy)
                    ? Command::SUCCESS
                    : Command::FAILURE;
            }

            if ($options->manualMode) {
                if (!$this->ytDlpClient->listFormats($videoUrl, $options->currentProxy, $options->insecure)) {
                    return Command::FAILURE;
                }
                $choice = trim($this->prompter->ask("Введи код формата для загрузки (или нажми Enter, чтобы скачать 'best'): "));
                $formatToDownload = $choice !== '' ? $choice : 'best';
                $this->logger->info("Выбран формат: '" . $formatToDownload . "'");
                $result = $this->downloaderService->downloadVideo(
                    $videoUrl,
                    $formatToDownload,
                    $options->currentProxy,
                    $options->insecure,
                    $options->outputFormat,
                    $options->dryRun,
                );

                return in_array($result->status, ['completed', 'skipped', 'cancelled'], true)
                    ? Command::SUCCESS
                    : Command::FAILURE;
            }

            $this->logger->info('⚡️ Автоматический режим: скачиваю лучшее качество...');
            $result = $this->downloaderService->downloadVideo(
                $videoUrl,
                'best',
                $options->currentProxy,
                $options->insecure,
                $options->outputFormat,
                $options->dryRun,
            );

            return in_array($result->status, ['completed', 'skipped', 'cancelled'], true)
                ? Command::SUCCESS
                : Command::FAILURE;
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

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
