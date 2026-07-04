<?php

declare(strict_types=1);

namespace YtdPhp;

use Symfony\Component\Console\Application as SymfonyApplication;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Command\YtdCommand;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Diagnostics\DoctorService;
use YtdPhp\Download\DownloaderService;
use YtdPhp\Shared\InputPrompter;
use YtdPhp\Playlist\PlaylistFlowService;
use YtdPhp\Playlist\PlaylistService;
use YtdPhp\Routing\RoutingService;
use YtdPhp\Download\SingleVideoFlowService;
use YtdPhp\Download\YtDlpClient;
use YtdPhp\Download\YtDlpGateway;

final class Application
{
    public function __construct(
        private readonly RuntimeBootstrap $bootstrap,
        private readonly ConsoleLogger $logger,
        private readonly DoctorService $doctorService,
        private readonly RoutingService $routingService,
        private readonly YtDlpGateway $ytDlpClient,
        private readonly PlaylistService $playlistService,
        private readonly SingleVideoFlowService $singleVideoFlowService,
        private readonly PlaylistFlowService $playlistFlowService,
    ) {}

    public static function createDefault(RuntimeBootstrap $bootstrap): self
    {
        $logger = new ConsoleLogger();
        $prompter = new InputPrompter();
        $routingService = new RoutingService($bootstrap);
        $ytDlpClient = new YtDlpClient($logger);
        $downloaderService = new DownloaderService($ytDlpClient, $bootstrap, $logger, $prompter);
        $playlistService = new PlaylistService($ytDlpClient, $bootstrap, $downloaderService, $logger, $prompter);
        $singleVideoFlowService = new SingleVideoFlowService($logger, $prompter, $ytDlpClient, $downloaderService);
        $playlistFlowService = new PlaylistFlowService($logger, $playlistService);
        $doctorService = new DoctorService($bootstrap, $routingService);

        return new self(
            $bootstrap,
            $logger,
            $doctorService,
            $routingService,
            $ytDlpClient,
            $playlistService,
            $singleVideoFlowService,
            $playlistFlowService,
        );
    }

    public function toSymfonyApplication(): SymfonyApplication
    {
        $application = new SymfonyApplication('YTD', '0.1.0');
        $application->setAutoExit(false);
        $application->addCommand(new YtdCommand(
            $this->bootstrap,
            $this->logger,
            $this->doctorService,
            $this->routingService,
            $this->ytDlpClient,
            $this->playlistService,
            $this->singleVideoFlowService,
            $this->playlistFlowService,
        ));
        $application->setDefaultCommand('ytd', true);

        return $application;
    }
}
