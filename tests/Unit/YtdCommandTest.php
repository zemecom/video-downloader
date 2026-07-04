<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use YtdPhp\Runtime\RuntimeBootstrap;
use YtdPhp\Command\YtdCommand;
use YtdPhp\Runtime\RuntimeOptions;
use YtdPhp\Shared\UserFacingException;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Diagnostics\DoctorService;
use YtdPhp\Download\DownloaderService;
use YtdPhp\Shared\InputPrompter;
use YtdPhp\Playlist\PlaylistFlowService;
use YtdPhp\Playlist\PlaylistService;
use YtdPhp\Routing\RoutingService;
use YtdPhp\Download\SingleVideoFlowService;
use YtdPhp\Download\YtDlp\YtDlpClient;

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
        self::assertSame('best', $options->qualityPreset);
    }

    public function testBuildRuntimeOptionsEnablesFastModeFromCliFlag(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
            '--fast' => true,
        ], $command->getDefinition());

        $options = $this->buildRuntimeOptions($command, $input);

        self::assertTrue($options->fastMode);
    }

    public function testBuildRuntimeOptionsRejectsFastModeWithAudioOnly(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
            '--fast' => true,
            '--audio' => true,
        ], $command->getDefinition());

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('`--fast` нельзя использовать вместе с `--audio`');

        $this->buildRuntimeOptions($command, $input);
    }

    public function testBuildRuntimeOptionsRejectsFastModeWithManualMode(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
            '--fast' => true,
            '--manual' => true,
        ], $command->getDefinition());

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('`--fast` пока не поддерживает `--manual`');

        $this->buildRuntimeOptions($command, $input);
    }

    public function testBuildRuntimeOptionsUsesConcurrentFragmentsFromEnvByDefault(): void
    {
        putenv('CONCURRENT_FRAGMENTS=9');

        try {
            $command = $this->makeCommand();
            $input = new ArrayInput([
                'url' => 'https://www.youtube.com/watch?v=test',
                '--no-proxy' => true,
            ], $command->getDefinition());

            $options = $this->buildRuntimeOptions($command, $input);

            self::assertSame(9, $options->concurrentFragments);
        } finally {
            putenv('CONCURRENT_FRAGMENTS');
        }
    }

    public function testBuildRuntimeOptionsAllowsConcurrentFragmentsOverrideFromCli(): void
    {
        putenv('CONCURRENT_FRAGMENTS=9');

        try {
            $command = $this->makeCommand();
            $input = new ArrayInput([
                'url' => 'https://www.youtube.com/watch?v=test',
                '--no-proxy' => true,
                '--concurrent-fragments' => '4',
            ], $command->getDefinition());

            $options = $this->buildRuntimeOptions($command, $input);

            self::assertSame(4, $options->concurrentFragments);
        } finally {
            putenv('CONCURRENT_FRAGMENTS');
        }
    }

    public function testBuildRuntimeOptionsUsesConcurrentDownloadsFromEnvByDefault(): void
    {
        putenv('CONCURRENT_DOWNLOADS=3');

        try {
            $command = $this->makeCommand();
            $input = new ArrayInput([
                'url' => 'https://www.youtube.com/watch?v=test',
                '--no-proxy' => true,
            ], $command->getDefinition());

            $options = $this->buildRuntimeOptions($command, $input);

            self::assertSame(3, $options->concurrentDownloads);
        } finally {
            putenv('CONCURRENT_DOWNLOADS');
        }
    }

    public function testBuildRuntimeOptionsAcceptsManualOutputControls(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
            '-Q' => 'm',
            '--output-format' => 'mp4',
            '--download-dir' => '~/Downloads/Test',
            '--progress-newline' => true,
            '--progress-delta' => '1.5',
        ], $command->getDefinition());

        $options = $this->buildRuntimeOptions($command, $input);

        self::assertSame('medium', $options->qualityPreset);
        self::assertSame('mp4', $options->outputFormat);
        self::assertSame('~/Downloads/Test', $options->downloadDir);
        self::assertTrue($options->progressNewline);
        self::assertSame('1.5', $options->progressDelta);
    }

    public function testBuildRuntimeOptionsAllowsDisablingProgressNewlineFromCli(): void
    {
        putenv('YTD_PROGRESS_NEWLINE=1');

        try {
            $command = $this->makeCommand();
            $input = new ArrayInput([
                'url' => 'https://www.youtube.com/watch?v=test',
                '--no-proxy' => true,
                '--no-progress-newline' => true,
            ], $command->getDefinition());

            $options = $this->buildRuntimeOptions($command, $input);

            self::assertFalse($options->progressNewline);
        } finally {
            putenv('YTD_PROGRESS_NEWLINE');
        }
    }

    public function testBuildRuntimeOptionsAcceptsLowQualityAlias(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
            '--quality' => 'low',
        ], $command->getDefinition());

        $options = $this->buildRuntimeOptions($command, $input);

        self::assertSame('low', $options->qualityPreset);
    }

    public function testBuildRuntimeOptionsRejectsUnknownQualityAlias(): void
    {
        $command = $this->makeCommand();
        $input = new ArrayInput([
            'url' => 'https://www.youtube.com/watch?v=test',
            '--no-proxy' => true,
            '--quality' => 'ultra',
        ], $command->getDefinition());

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('`--quality` поддерживает только b/best, m/medium или l/low.');

        $this->buildRuntimeOptions($command, $input);
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
