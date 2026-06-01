<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use function ltrim;
use function parse_url;
use function str_ends_with;
use function strtolower;

final class YtDlpCommandBuilder
{
    private const BEST_FORMAT = 'bestvideo+bestaudio/best';
    private const BEST_NON_AV1_FORMAT = 'bestvideo[vcodec!^=av01]+bestaudio/best[vcodec!^=av01]';

    /** @var list<string> */
    private array $command;

    /**
     * @param list<string> $baseFlags
     */
    public function __construct(
        private readonly ?string $url = null,
        bool $allowPlaylist = false,
        array $baseFlags = ['--no-warnings', '--ignore-config'],
    ) {
        $this->command = ['yt-dlp'];
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
    public function buildForDownload(string $formatCode, string $outputTemplate, string $outputFormat = 'mkv'): array
    {
        $command = [...$this->command, '-o', $outputTemplate];
        $command = $this->applyFormatArgs($command, $formatCode, $outputFormat);
        $command[] = '--downloader-args';
        $command[] = 'ffmpeg_i:-http_persistent 0';
        $command[] = '--progress';
        $command[] = '--newline';
        $command[] = '--concurrent-fragments';
        $command[] = '10';

        return $command;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function applyFormatArgs(array $command, string $formatCode, string $outputFormat): array
    {
        return match ($formatCode) {
            'bestaudio' => [...$command, '-f', 'bestaudio/best', '--extract-audio', '--audio-format', 'opus'],
            'best' => [...$command, '-f', $this->bestFormatSelector(), '--merge-output-format', $outputFormat],
            default => [...$command, '-f', $formatCode, '--merge-output-format', $outputFormat],
        };
    }

    private function bestFormatSelector(): string
    {
        return $this->isYoutubeUrl($this->url)
            ? self::BEST_NON_AV1_FORMAT
            : self::BEST_FORMAT;
    }

    private function isYoutubeUrl(?string $videoUrl): bool
    {
        if (!is_string($videoUrl) || $videoUrl === '') {
            return false;
        }

        $hostname = strtolower((string) (parse_url($videoUrl, PHP_URL_HOST) ?? ''));
        if ($hostname === '') {
            $hostname = strtolower((string) (parse_url('//' . ltrim($videoUrl, '/'), PHP_URL_HOST) ?? ''));
        }

        return $hostname === 'youtube.com'
            || $hostname === 'youtu.be'
            || str_ends_with($hostname, '.youtube.com')
            || str_ends_with($hostname, '.youtu.be');
    }
}
