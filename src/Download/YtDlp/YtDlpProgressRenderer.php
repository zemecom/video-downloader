<?php

declare(strict_types=1);

namespace YtdPhp\Download\YtDlp;

use YtdPhp\Shared\ConsoleLogger;

final class YtDlpProgressRenderer
{
    private const int PANEL_WIDTH = 118;
    private const int BAR_WIDTH = 26;

    private const string COLOR_RESET = "\033[0m";
    private const string COLOR_DOWNLOAD = "\033[38;5;67m";
    private const string COLOR_VIDEO = "\033[38;5;73m";
    private const string COLOR_AUDIO = "\033[38;5;180m";
    private const string COLOR_DEFAULT = "\033[38;5;244m";
    private const string COLOR_FRAME = "\033[38;5;240m";

    /** @var array<string, array{percent:?float, total:string, speed:string, eta:string, detail:string}> */
    private array $states = [];

    private bool $rendered = false;

    private int $renderedLineCount = 0;

    /**
     * @param list<string> $labels
     */
    public function __construct(
        private ConsoleLogger $logger,
        array $labels = ['audio', 'video'],
    ) {
        foreach ($labels as $label) {
            $this->states[$label] = $this->initialState();
        }
    }

    public function consume(string $label, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }
        $this->states[$label] ??= $this->initialState();

        $normalized = \str_replace("\r", "\n", $chunk);
        $lines = \preg_split('/\n+/', $normalized);
        if (!\is_array($lines)) {
            return;
        }

        foreach (\array_filter($lines, static fn(string $line): bool => \trim($line) !== '') as $line) {
            $this->consumeLine($label, \trim($line));
        }
    }

    public function finish(): void
    {
        if ($this->rendered) {
            $this->logger->raw(PHP_EOL);
            $this->rendered = false;
        }
    }

    /**
     * @return array{percent:?float, total:string, speed:string, eta:string, detail:string}
     */
    private function initialState(): array
    {
        return [
            'percent' => null,
            'total' => '',
            'speed' => '',
            'eta' => '',
            'detail' => 'standby',
        ];
    }

    private function consumeLine(string $label, string $line): void
    {
        if ($this->updateProgressState($label, $line)) {
            $this->render();

            return;
        }

        if (\str_starts_with($line, '[download] Destination:')) {
            $this->states[$label]['detail'] = 'stream init';
            $this->render();

            return;
        }

        if (\str_starts_with($line, '[info]')) {
            $this->states[$label]['detail'] = 'probe';
            $this->render();

            return;
        }

        if (\str_starts_with($line, '[Fixup')) {
            $this->states[$label]['detail'] = 'container fix';
            $this->render();

            return;
        }

        if ($this->updateDiagnosticState($label, $line)) {
            $this->render();

            return;
        }

        if ($this->updateRoutineExtractorState($label, $line)) {
            $this->render();

            return;
        }

        $this->writeLogLine($label, $line);
    }

    private function updateDiagnosticState(string $label, string $line): bool
    {
        if (\preg_match('/^(ERROR|WARNING):\s*(.+)$/i', $line, $matches) !== 1) {
            return false;
        }

        $this->states[$label]['detail'] = $this->formatDiagnosticDetail($matches[1], \trim($matches[2]));

        return true;
    }

    private function formatDiagnosticDetail(string $level, string $message): string
    {
        $normalizedLevel = \strtoupper($level);
        if (\preg_match('/HTTP Error\s+(\d+):\s*(.+)$/i', $message, $matches) === 1) {
            return $normalizedLevel . ': HTTP ' . \trim($matches[1]) . ' ' . \trim($matches[2]);
        }

        return $normalizedLevel . ': ' . $message;
    }

    private function updateRoutineExtractorState(string $label, string $line): bool
    {
        if (\str_contains($line, 'ERROR:') || \str_contains($line, 'WARNING:')) {
            return false;
        }

        $detail = $this->routineDetail($line);
        if ($detail === null) {
            return false;
        }

        $this->states[$label]['detail'] = $detail;

        return true;
    }

    private function routineDetail(string $line): ?string
    {
        if (\preg_match('/^\[(?:youtube|generic|extractor|jsc:[^\]]+)\]/', $line) !== 1) {
            return null;
        }

        $normalized = \strtolower($line);

        if (\str_contains($normalized, 'extracting url')) {
            return 'link scan';
        }
        if (\str_contains($normalized, 'downloading webpage')) {
            return 'web probe';
        }
        if (\str_contains($normalized, 'api json')) {
            return 'player api';
        }
        if (\str_contains($normalized, 'downloading player')) {
            return 'player core';
        }
        if (\str_contains($normalized, 'solving js challenges')) {
            return 'cipher solve';
        }
        if (\str_contains($normalized, 'downloading m3u8') || \str_contains($normalized, 'downloading mpd')) {
            return 'manifest';
        }
        if (\str_contains($normalized, 'checking')) {
            return 'check';
        }

        return 'extractor';
    }

    private function updateProgressState(string $label, string $line): bool
    {
        if (\preg_match('/^\[download\]\s+([0-9.]+)%\s+of\s+(.+?)\s+at\s+(.+?)\s+ETA\s+(.+)$/', $line, $matches) === 1) {
            $this->states[$label] = [
                'percent' => (float) $matches[1],
                'total' => \trim($matches[2]),
                'speed' => \trim($matches[3]),
                'eta' => \trim($matches[4]),
                'detail' => '',
            ];

            return true;
        }

        if (\preg_match('/^\[download\]\s+100%\s+of\s+(.+?)\s+in\s+(.+?)\s+at\s+(.+)$/', $line, $matches) === 1) {
            $this->states[$label] = [
                'percent' => 100.0,
                'total' => \trim($matches[1]),
                'speed' => \trim($matches[3]),
                'eta' => 'done ' . \trim($matches[2]),
                'detail' => '',
            ];

            return true;
        }

        if (\preg_match('/^\[download\]\s+100%\s+of\s+(.+)$/', $line, $matches) === 1) {
            $this->states[$label] = [
                'percent' => 100.0,
                'total' => \trim($matches[1]),
                'speed' => '',
                'eta' => 'done',
                'detail' => '',
            ];

            return true;
        }

        return false;
    }

    private function render(): void
    {
        if ($this->rendered) {
            $this->clearRenderedLines();
        }

        $output = $this->buildRenderedOutput();
        $this->logger->raw($output);
        $this->rendered = true;
        $this->renderedLineCount = \substr_count($output, PHP_EOL) + 1;
    }

    private function writeLogLine(string $label, string $line): void
    {
        if ($this->rendered) {
            $this->clearRenderedLines();
            $this->rendered = false;
        }

        $this->logger->info($this->colorize($label, $this->logPrefix($label) . ' ' . $line));
        $this->render();
    }

    private function buildRenderedOutput(): string
    {
        $lines = [
            $this->frameColor($this->buildTopBorder()),
        ];
        foreach (\array_keys($this->states) as $label) {
            $lines[] = $this->buildLine($label);
        }
        $lines[] = $this->frameColor($this->buildBottomBorder());

        return \implode(PHP_EOL, $lines);
    }

    private function clearRenderedLines(): void
    {
        $count = \max(1, $this->renderedLineCount);
        for ($index = 0; $index < $count - 1; ++$index) {
            $this->logger->raw("\r\033[2K\033[1A");
        }
        $this->logger->raw("\r\033[2K");
        $this->renderedLineCount = 0;
    }

    private function buildLine(string $label): string
    {
        $state = $this->states[$label];
        $percent = $state['percent'];
        $percentLabel = $percent === null ? ' --.-%' : \sprintf('%5.1f%%', $percent);
        $parts = [
            $this->labelTitle($label),
            $this->buildBar($percent),
            $percentLabel,
        ];
        $metrics = [];

        if ($state['total'] !== '') {
            $metrics[] = $state['total'];
        }
        if ($state['speed'] !== '') {
            $metrics[] = $state['speed'];
        }
        if ($state['eta'] !== '') {
            $metrics[] = 'ETA ' . $state['eta'];
        }
        if ($state['detail'] !== '') {
            $metrics[] = $state['detail'];
        }
        if ($metrics !== []) {
            $parts[] = \implode(' · ', $metrics);
        }

        return $this->colorize($label, $this->frameLine(\implode('  ', $parts)));
    }

    private function buildBar(?float $percent): string
    {
        if ($percent === null) {
            return '⟦' . \str_repeat('·', self::BAR_WIDTH) . '⟧';
        }

        $filled = (int) \round(\max(0.0, \min(100.0, $percent)) / 100 * self::BAR_WIDTH);

        return '⟦' . \str_repeat('▰', $filled) . \str_repeat('▱', self::BAR_WIDTH - $filled) . '⟧';
    }

    private function buildTopBorder(): string
    {
        $title = '─ YTD STREAM MATRIX ';
        $fill = \str_repeat('─', \max(0, self::PANEL_WIDTH - 2 - \mb_strlen($title)));

        return '╭' . $title . $fill . '╮';
    }

    private function buildBottomBorder(): string
    {
        return '╰' . \str_repeat('─', self::PANEL_WIDTH - 2) . '╯';
    }

    private function frameLine(string $content): string
    {
        $innerWidth = self::PANEL_WIDTH - 2;
        $content = ' ' . $this->fitLine($content, $innerWidth - 2) . ' ';
        $padding = \str_repeat(' ', \max(0, $innerWidth - \mb_strlen($content)));

        return '│' . $content . $padding . '│';
    }

    private function fitLine(string $line, int $width): string
    {
        if (\mb_strlen($line) <= $width) {
            return $line;
        }

        return \mb_substr($line, 0, $width - 3) . '...';
    }

    private function labelTitle(string $label): string
    {
        return match ($label) {
            'download' => '◈ DOWNLOAD',
            'video' => '◆ VIDEO',
            'audio' => '◇ AUDIO',
            default => $label,
        };
    }

    private function logPrefix(string $label): string
    {
        return match ($label) {
            'download' => '◈ DOWNLOAD │',
            'video' => '◆ VIDEO │',
            'audio' => '◇ AUDIO │',
            default => $label . ' │',
        };
    }

    private function colorize(string $label, string $text): string
    {
        return $this->labelColor($label) . $text . self::COLOR_RESET;
    }

    private function labelColor(string $label): string
    {
        return match ($label) {
            'download' => self::COLOR_DOWNLOAD,
            'video' => self::COLOR_VIDEO,
            'audio' => self::COLOR_AUDIO,
            default => self::COLOR_DEFAULT,
        };
    }

    private function frameColor(string $text): string
    {
        return self::COLOR_FRAME . $text . self::COLOR_RESET;
    }
}
