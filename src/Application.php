<?php

declare(strict_types=1);

namespace YtdPhp;

use Symfony\Component\Console\Application as SymfonyApplication;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Command\YtdCommand;
use YtdPhp\Service\ConsoleLogger;
use YtdPhp\Service\DoctorService;
use YtdPhp\Service\DownloaderService;
use YtdPhp\Service\InputPrompter;
use YtdPhp\Service\PlaylistService;
use YtdPhp\Service\RoutingService;
use YtdPhp\Service\YtDlpClient;

final class Application
{
    public function __construct(
        private readonly RuntimeBootstrap $bootstrap,
        private readonly ConsoleLogger $logger,
        private readonly InputPrompter $prompter,
        private readonly DoctorService $doctorService,
        private readonly RoutingService $routingService,
        private readonly YtDlpClient $ytDlpClient,
        private readonly DownloaderService $downloaderService,
        private readonly PlaylistService $playlistService,
    ) {}

    public static function createDefault(RuntimeBootstrap $bootstrap): self
    {
        $logger = new ConsoleLogger();
        $prompter = new InputPrompter();
        $routingService = new RoutingService($bootstrap);
        $ytDlpClient = new YtDlpClient($logger);
        $downloaderService = new DownloaderService($ytDlpClient, $bootstrap, $logger, $prompter);
        $playlistService = new PlaylistService($ytDlpClient, $bootstrap, $downloaderService, $logger, $prompter);
        $doctorService = new DoctorService($bootstrap, $routingService);

        return new self(
            $bootstrap,
            $logger,
            $prompter,
            $doctorService,
            $routingService,
            $ytDlpClient,
            $downloaderService,
            $playlistService,
        );
    }

    public function toSymfonyApplication(): SymfonyApplication
    {
        $application = new SymfonyApplication('YTD', '0.1.0');
        $application->setAutoExit(false);
        $application->add(new YtdCommand(
            $this->bootstrap,
            $this->logger,
            $this->prompter,
            $this->doctorService,
            $this->routingService,
            $this->ytDlpClient,
            $this->downloaderService,
            $this->playlistService,
        ));
        $application->setDefaultCommand('ytd', true);

        return $application;
    }
}
