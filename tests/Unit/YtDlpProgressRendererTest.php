<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use YtdPhp\Shared\ConsoleLogger;
use YtdPhp\Download\YtDlp\YtDlpProgressRenderer;

final class YtDlpProgressRendererTest extends TestCase
{
    public function testRendererBuildsTwoStreamProgressBars(): void
    {
        $output = new BufferedOutput();
        $renderer = new YtDlpProgressRenderer(new ConsoleLogger($output));

        $renderer->consume('video', "[download]   12.5% of  800.00MiB at 5.00MiB/s ETA 02:20\n");
        $renderer->consume('audio', "[download] 100% of   40.00MiB in 00:00:08 at 5.00MiB/s\n");
        $renderer->finish();

        $contents = $output->fetch();
        $audioPosition = strpos($contents, '◇ AUDIO');
        $videoPosition = strpos($contents, '◆ VIDEO');
        if (!is_int($audioPosition) || !is_int($videoPosition)) {
            self::fail('Expected audio and video progress labels to be rendered.');
        }

        self::assertLessThan($videoPosition, $audioPosition);
        self::assertStringContainsString('╭─ YTD STREAM MATRIX', $contents);
        self::assertStringContainsString("\033[38;5;73m│ ◆ VIDEO", $contents);
        self::assertStringContainsString("\033[38;5;180m│ ◇ AUDIO", $contents);
        self::assertStringContainsString('◆ VIDEO  ⟦▰▰▰▱', $contents);
        self::assertStringContainsString('12.5%  800.00MiB · 5.00MiB/s · ETA 02:20', $contents);
        self::assertStringContainsString('◇ AUDIO  ⟦▰▰▰▰▰▰▰▰', $contents);
        self::assertStringContainsString('100.0%  40.00MiB · 5.00MiB/s · ETA done 00:00:08', $contents);
    }

    public function testRendererKeepsErrorLinesVisible(): void
    {
        $output = new BufferedOutput();
        $renderer = new YtDlpProgressRenderer(new ConsoleLogger($output));

        $renderer->consume('video', "[download]   10.0% of  800.00MiB at 5.00MiB/s ETA 02:24\n");
        $renderer->consume('video', "ERROR: unable to download video data\n");
        $renderer->finish();

        $contents = $output->fetch();

        self::assertStringContainsString('◆ VIDEO', $contents);
        self::assertStringContainsString('ERROR:', $contents);
        self::assertStringNotContainsString('◆ VIDEO │ ERROR: unable to download video data', $contents);
    }

    public function testRendererCompactsHttpErrorsIntoPanelState(): void
    {
        $output = new BufferedOutput();
        $renderer = new YtDlpProgressRenderer(new ConsoleLogger($output));

        $renderer->consume('video', "[download]    2.0% of  408.80MiB at 11.07MiB/s ETA 00:36\n");
        $renderer->consume('video', "ERROR: unable to download video data: HTTP Error 403: Forbidden\n");
        $renderer->finish();

        $contents = $output->fetch();

        self::assertStringContainsString('ERROR: HTTP 403 Forbidden', $contents);
        self::assertStringNotContainsString('◆ VIDEO │ ERROR: unable to download video data', $contents);
    }

    public function testRendererCompactsRoutineExtractorLinesIntoPanelState(): void
    {
        $output = new BufferedOutput();
        $renderer = new YtDlpProgressRenderer(new ConsoleLogger($output));

        $renderer->consume('video', "[youtube] Extracting URL: https://www.youtube.com/watch?v=abc\n");
        $renderer->consume('audio', "[youtube] abc: Downloading webpage\n");
        $renderer->finish();

        $contents = $output->fetch();

        self::assertStringNotContainsString('◆ VIDEO │ [youtube]', $contents);
        self::assertStringNotContainsString('◇ AUDIO │ [youtube]', $contents);
        self::assertStringContainsString('link scan', $contents);
        self::assertStringContainsString('web probe', $contents);
    }

    public function testRendererCanRenderSingleDownloadLine(): void
    {
        $output = new BufferedOutput();
        $renderer = new YtDlpProgressRenderer(new ConsoleLogger($output), ['download']);

        $renderer->consume('download', "[download]   50.0% of  100.00MiB at 10.00MiB/s ETA 00:05\n");
        $renderer->finish();

        $contents = $output->fetch();

        self::assertStringContainsString("\033[38;5;67m│ ◈ DOWNLOAD", $contents);
        self::assertStringContainsString('◈ DOWNLOAD  ⟦▰▰▰▰▰▰▰▰▰▰▰▰▰▱', $contents);
        self::assertStringContainsString('50.0%  100.00MiB · 10.00MiB/s · ETA 00:05', $contents);
    }
}
