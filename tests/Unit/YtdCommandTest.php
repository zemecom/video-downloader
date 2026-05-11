<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Command\YtdCommand;
use YtdPhp\Dto\RuntimeOptions;
use YtdPhp\Service\ConsoleLogger;
use YtdPhp\Service\DoctorService;
use YtdPhp\Service\DownloaderService;
use YtdPhp\Service\InputPrompter;
use YtdPhp\Service\PlaylistFlowService;
use YtdPhp\Service\PlaylistService;
use YtdPhp\Service\RoutingService;
use YtdPhp\Service\SingleVideoFlowService;
use YtdPhp\Service\YtDlpClient;

final class YtdCommandTest extends TestCase
{
    public function testBuildRuntimeOptionsEnablesAudioOnlyModeFromCliFlag(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
            '--audio' => true,
        ], $command->getDefinition());

        $options = $this->buildRuntimeOptions($command, $input);

        self::assertTrue($options->audioOnly);
    }

    public function testBuildRuntimeOptionsKeepsAudioOnlyDisabledByDefault(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
        ], $command->getDefinition());

        $options = $this->buildRuntimeOptions($command, $input);

        self::assertFalse($options->audioOnly);
    }

    private function makeCommand(): YtdCommand
    {
        $projectRoot = dirname(__DIR__, 2);
        $bootstrap = new RuntimeBootstrap($projectRoot);
        $logger = new ConsoleLogger();
        $prompter = new InputPrompter();
        $routingService = new RoutingService($bootstrap);
        $ytDlpClient = new YtDlpClient($logger);
        $downloaderService = new DownloaderService($ytDlpClient, $bootstrap, $logger, $prompter);
        $playlistService = new PlaylistService($ytDlpClient, $bootstrap, $downloaderService, $logger, $prompter);
        $singleVideoFlowService = new SingleVideoFlowService($logger, $prompter, $ytDlpClient, $downloaderService);
        $playlistFlowService = new PlaylistFlowService($logger, $playlistService);
        $doctorService = new DoctorService($bootstrap, $routingService);

        return new YtdCommand(
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

    private function buildRuntimeOptions(YtdCommand $command, ArrayInput $input): RuntimeOptions
    {
        $method = new ReflectionMethod($command, 'buildRuntimeOptions');

        /** @var RuntimeOptions $options */
        $options = $method->invoke($command, $input, 'https://www.youtube.com/watch?v=test');

        return $options;
    }
}
