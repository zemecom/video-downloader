<?php

declare(strict_types=1);

namespace YtdPhp\Download\YtDlp;

final class YtDlpCommandBuilder
{
    private const string BEST_FORMAT = 'bestvideo+bestaudio/best';

    private const string FHD_FORMAT = 'bestvideo[height<=1080]+bestaudio/best[height<=1080]/bestvideo[height<=1080]+bestaudio/best[height<=1080]';
    private const string FHD_NON_AV1_FORMAT = 'bestvideo[vcodec!^=av01][height<=1080]+bestaudio/best[vcodec!^=av01][height<=1080]/bestvideo[height<=1080]+bestaudio/best[height<=1080]';
    private const string FHD_BROWSER_MP4_FORMAT = 'bestvideo[ext=mp4][vcodec^=avc1][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4][vcodec^=avc1][acodec^=mp4a][height<=1080]/bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4][height<=1080]';

    private const string MEDIUM_FORMAT = 'bestvideo[height<=720]+bestaudio/best[height<=720]/bestvideo[height<=720]+bestaudio/best[height<=720]';
    private const string MEDIUM_NON_AV1_FORMAT = 'bestvideo[vcodec!^=av01][height<=720]+bestaudio/best[vcodec!^=av01][height<=720]/bestvideo[height<=720]+bestaudio/best[height<=720]';
    private const string MEDIUM_BROWSER_MP4_FORMAT = 'bestvideo[ext=mp4][vcodec^=avc1][height<=720]+bestaudio[ext=m4a]/best[ext=mp4][vcodec^=avc1][acodec^=mp4a][height<=720]/bestvideo[ext=mp4][height<=720]+bestaudio[ext=m4a]/best[ext=mp4][height<=720]';
    private const string LOW_FORMAT = 'bestvideo[height<=480]+bestaudio/best[height<=480]/bestvideo[height<=480]+bestaudio/best[height<=480]';
    private const string LOW_NON_AV1_FORMAT = 'bestvideo[vcodec!^=av01][height<=480]+bestaudio/best[vcodec!^=av01][height<=480]/bestvideo[height<=480]+bestaudio/best[height<=480]';
    private const string LOW_BROWSER_MP4_FORMAT = 'bestvideo[ext=mp4][vcodec^=avc1][height<=480]+bestaudio[ext=m4a]/best[ext=mp4][vcodec^=avc1][acodec^=mp4a][height<=480]/bestvideo[ext=mp4][height<=480]+bestaudio[ext=m4a]/best[ext=mp4][height<=480]';

    /** @var list<string> */
    private array $command = ['yt-dlp'];

    /**
     * @param list<string> $baseFlags
     */
    public function __construct(
        private readonly ?string $url = null,
        bool $allowPlaylist = false,
        array $baseFlags = ['--no-warnings', '--ignore-config'],
    ) {
        $flags = $baseFlags;
        if (!$allowPlaylist) {
            array_unshift($flags, '--no-playlist');
        }
        $this->command = [...$this->command, ...$flags];
    }

    public function setProxy(?string $proxy): self
    {
        if (is_string($proxy) && $proxy !== '') {
            $this->command[] = $proxy;
        }

        return $this;
    }

    public function setInsecure(bool $insecure): self
    {
        if ($insecure) {
            $this->command[] = '--no-check-certificate';
        }

        return $this;
    }

    public function addArg(string $arg): self
    {
        $this->command[] = $arg;

        return $this;
    }

    public function loadInfoJson(?string $jsonPath): self
    {
        if (is_string($jsonPath) && $jsonPath !== '') {
            $this->command[] = '--load-info-json';
            $this->command[] = $jsonPath;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function buildForMetadata(): array
    {
        $command = $this->command;
        array_splice($command, 1, 0, ['--dump-json']);
        if (is_string($this->url) && $this->url !== '') {
            $command[] = $this->url;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function buildForPlaylistMetadata(): array
    {
        $command = [...$this->command, '--flat-playlist', '--dump-single-json'];
        if (is_string($this->url) && $this->url !== '') {
            $command[] = $this->url;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function buildForPlaylistItemMetadata(int $playlistIndex): array
    {
        $command = [...$this->command, '--dump-single-json', '--playlist-items', (string) $playlistIndex];
        if (is_string($this->url) && $this->url !== '') {
            $command[] = $this->url;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function buildForFilename(?string $outputPath = null): array
    {
        $command = $this->command;
        array_splice($command, 1, 0, ['--get-filename']);
        if (is_string($outputPath) && $outputPath !== '') {
            $command[] = '-o';
            $command[] = $outputPath;
        }
        if (is_string($this->url) && $this->url !== '') {
            $command[] = $this->url;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function buildForDownload(
        string $formatCode,
        string $outputTemplate,
        string $outputFormat = 'mkv',
        bool $lineBufferedProgress = false,
        int $concurrentFragments = 20,
        string $progressDelta = '0.5',
        bool $allow4k = false,
    ): array {
        $command = [...$this->command, '-o', $outputTemplate];
        $command = $this->applyFormatArgs($command, $formatCode, $outputFormat, $allow4k);
        $command[] = '--downloader-args';
        $command[] = 'ffmpeg_i:-http_persistent 0';
        $command[] = '--postprocessor-args';
        $command[] = 'ffmpeg:-movflags faststart';
        $command[] = '--continue';
        $command[] = '--progress';
        $command[] = '--progress-delta';
        $command[] = $progressDelta;
        if ($lineBufferedProgress) {
            $command[] = '--newline';
        }
        $command[] = '--concurrent-fragments';
        $command[] = (string) max(1, $concurrentFragments);

        if (is_string($this->url) && $this->url !== '' && !\in_array('--load-info-json', $command, true)) {
            $command[] = $this->url;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public function buildForRawStreamDownload(
        string $formatCode,
        string $outputPath,
        bool $lineBufferedProgress = true,
        int $concurrentFragments = 20,
        string $progressDelta = '0.5',
    ): array {
        $command = [...$this->command, '-o', $outputPath, '-f', $formatCode];
        $command[] = '--continue';
        $command[] = '--progress';
        $command[] = '--progress-delta';
        $command[] = $progressDelta;
        if ($lineBufferedProgress) {
            $command[] = '--newline';
        }
        $command[] = '--concurrent-fragments';
        $command[] = (string) max(1, $concurrentFragments);
        if (is_string($this->url) && $this->url !== '' && !\in_array('--load-info-json', $command, true)) {
            $command[] = $this->url;
        }

        return $command;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function applyFormatArgs(array $command, string $formatCode, string $outputFormat, bool $allow4k): array
    {
        return match ($formatCode) {
            'bestaudio' => [...$command, '-f', 'bestaudio/best', '--extract-audio', '--audio-format', 'opus'],
            'best' => [...$command, '-f', $this->bestFormatSelector($outputFormat, $allow4k), '--merge-output-format', $outputFormat],
            'fhd' => [...$command, '-f', $this->fhdFormatSelector($outputFormat), '--merge-output-format', $outputFormat],
            'medium' => [...$command, '-f', $this->mediumFormatSelector($outputFormat), '--merge-output-format', $outputFormat],
            'low' => [...$command, '-f', $this->lowFormatSelector($outputFormat), '--merge-output-format', $outputFormat],
            default => [...$command, '-f', $formatCode, '--merge-output-format', $outputFormat],
        };
    }

    private function bestFormatSelector(string $outputFormat, bool $allow4k): string
    {
        return self::BEST_FORMAT;
    }

    private function fhdFormatSelector(string $outputFormat): string
    {
        if ($outputFormat === 'mp4') {
            return self::FHD_BROWSER_MP4_FORMAT . '/' . $this->fhdFallbackFormatSelector();
        }

        return $this->fhdFallbackFormatSelector();
    }

    private function fhdFallbackFormatSelector(): string
    {
        return $this->isYoutubeUrl($this->url)
            ? self::FHD_NON_AV1_FORMAT
            : self::FHD_FORMAT;
    }

    private function mediumFormatSelector(string $outputFormat): string
    {
        if ($outputFormat === 'mp4') {
            return self::MEDIUM_BROWSER_MP4_FORMAT . '/' . $this->mediumFallbackFormatSelector();
        }

        return $this->mediumFallbackFormatSelector();
    }

    private function mediumFallbackFormatSelector(): string
    {
        return $this->isYoutubeUrl($this->url)
            ? self::MEDIUM_NON_AV1_FORMAT
            : self::MEDIUM_FORMAT;
    }

    private function lowFormatSelector(string $outputFormat): string
    {
        if ($outputFormat === 'mp4') {
            return self::LOW_BROWSER_MP4_FORMAT . '/' . $this->lowFallbackFormatSelector();
        }

        return $this->lowFallbackFormatSelector();
    }

    private function lowFallbackFormatSelector(): string
    {
        return $this->isYoutubeUrl($this->url)
            ? self::LOW_NON_AV1_FORMAT
            : self::LOW_FORMAT;
    }

    private function isYoutubeUrl(?string $videoUrl): bool
    {
        if (!is_string($videoUrl) || $videoUrl === '') {
            return false;
        }

        $hostname = \strtolower((string) (\parse_url($videoUrl, PHP_URL_HOST) ?? ''));
        if ($hostname === '') {
            $hostname = \strtolower((string) (\parse_url('//' . \ltrim($videoUrl, '/'), PHP_URL_HOST) ?? ''));
        }

        return $hostname === 'youtube.com'
            || $hostname === 'youtu.be'
            || \str_ends_with($hostname, '.youtube.com')
            || \str_ends_with($hostname, '.youtu.be');
    }
}
