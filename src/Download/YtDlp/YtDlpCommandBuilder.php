<?php

declare(strict_types=1);

namespace YtdPhp\Download\YtDlp;

final class YtDlpCommandBuilder
{
    private const string BEST_FORMAT = 'bestvideo+bestaudio/best';

    /** @var list<string> */
    private array $command;

    private ?string $audioLanguage = null;

    /**
     * @param list<string> $baseFlags
     */
    public function __construct(
        private readonly ?string $url = null,
        bool $allowPlaylist = false,
        array $baseFlags = ['--no-warnings', '--ignore-config'],
        ?string $audioLanguage = null,
    ) {
        $this->audioLanguage = $audioLanguage !== null
            ? self::normalizeLanguage($audioLanguage)
            : self::resolveConfiguredAudioLanguage();

        $ytDlp = \getenv('YT_DLP_PATH') ?: 'yt-dlp';
        $this->command = [$ytDlp];

        $socketTimeout = \trim((string) (\getenv('YTD_SOCKET_TIMEOUT') ?: '30'));
        if ($socketTimeout !== '' && \ctype_digit($socketTimeout) && !\in_array('--socket-timeout', $baseFlags, true)) {
            $baseFlags = [...$baseFlags, '--socket-timeout', $socketTimeout];
        }

        $flags = $baseFlags;
        if (!$allowPlaylist) {
            array_unshift($flags, '--no-playlist');
        }
        $this->command = [...$this->command, ...$flags];

        $defaultCookiesFile = self::resolveConfiguredCookiesFile();
        if (
            $defaultCookiesFile !== null
            && !\in_array('--cookies', $baseFlags, true)
            && !\in_array('--cookies-from-browser', $baseFlags, true)
        ) {
            $this->setCookiesFile($defaultCookiesFile);
        } else {
            $defaultCookies = \trim((string) (\getenv('YTD_COOKIES_FROM_BROWSER') ?: ''));
            if (
                $defaultCookies !== ''
                && !\in_array('--cookies-from-browser', $baseFlags, true)
                && !\in_array('--cookies', $baseFlags, true)
            ) {
                $this->setCookiesFromBrowser($defaultCookies);
            }
        }
    }

    public static function resolveConfiguredCookiesFile(): ?string
    {
        $cookiesFile = \trim((string) (\getenv('YTD_COOKIES_FILE') ?: ''));
        if ($cookiesFile === '' || \strtolower($cookiesFile) === 'none') {
            return null;
        }

        if (\str_starts_with($cookiesFile, '~')) {
            $home = \getenv('HOME');
            if (\is_string($home) && $home !== '') {
                $suffix = \ltrim(\substr($cookiesFile, 1), DIRECTORY_SEPARATOR);
                $cookiesFile = $suffix === '' ? $home : $home . DIRECTORY_SEPARATOR . $suffix;
            }
        } elseif (!\str_starts_with($cookiesFile, DIRECTORY_SEPARATOR) && !(\strlen($cookiesFile) > 1 && \ctype_alpha($cookiesFile[0]) && $cookiesFile[1] === ':')) {
            $projectRoot = \getenv('YTD_PROJECT_ROOT');
            $baseDir = (\is_string($projectRoot) && $projectRoot !== '') ? $projectRoot : (\getcwd() ?: \dirname(__DIR__, 3));
            $cookiesFile = \rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $cookiesFile;
        }

        return \is_file($cookiesFile) ? $cookiesFile : null;
    }

    public static function normalizeLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $trimmed = \strtolower(\trim($language));
        if ($trimmed === '' || \in_array($trimmed, ['none', 'off', '0', 'false', 'no'], true)) {
            return null;
        }

        return $trimmed;
    }

    public static function resolveConfiguredAudioLanguage(): ?string
    {
        $lang = \getenv('YTD_AUDIO_LANG');

        return \is_string($lang) ? self::normalizeLanguage($lang) : null;
    }

    public function setAudioLanguage(?string $language): self
    {
        $this->audioLanguage = self::normalizeLanguage($language);

        return $this;
    }

    public function getAudioLanguage(): ?string
    {
        return $this->audioLanguage;
    }

    public function setCookiesFile(?string $cookiesFile): self
    {
        $this->removeFlagWithValue('--cookies');

        if (is_string($cookiesFile)) {
            $trimmed = \trim($cookiesFile);
            if (\strtolower($trimmed) === 'none') {
                $this->removeFlagWithValue('--cookies-from-browser');
            } elseif ($trimmed !== '') {
                $this->removeFlagWithValue('--cookies-from-browser');
                $this->command[] = '--cookies';
                $this->command[] = $trimmed;
            }
        }

        return $this;
    }

    public function setCookiesFromBrowser(?string $cookiesFromBrowser): self
    {
        $this->removeFlagWithValue('--cookies-from-browser');

        if (is_string($cookiesFromBrowser)) {
            $trimmed = \trim($cookiesFromBrowser);
            if (\strtolower($trimmed) === 'none') {
                $this->removeFlagWithValue('--cookies');
            } elseif ($trimmed !== '') {
                $this->removeFlagWithValue('--cookies');
                $this->command[] = '--cookies-from-browser';
                $this->command[] = $trimmed;
            }
        }

        return $this;
    }

    private function removeFlagWithValue(string $flag): void
    {
        $filtered = [];
        $skipNext = false;
        foreach ($this->command as $arg) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if ($arg === $flag) {
                $skipNext = true;
                continue;
            }
            $filtered[] = $arg;
        }
        $this->command = $filtered;
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
        $command = $this->appendYoutubePlayerClient($command);
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
    public function buildForFilename(
        ?string $outputPath = null,
        string $formatCode = 'best',
        string $outputFormat = 'mkv',
        bool $allow4k = false,
    ): array {
        $command = $this->command;
        array_splice($command, 1, 0, ['--get-filename']);
        if (is_string($outputPath) && $outputPath !== '') {
            $command[] = '-o';
            $command[] = $outputPath;
        }
        $command = $this->applyFormatArgs($command, $formatCode, $outputFormat, $allow4k);
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
        $command = $this->appendYoutubePlayerClient($command);

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
        $command = $this->appendYoutubePlayerClient($command);
        if (is_string($this->url) && $this->url !== '' && !\in_array('--load-info-json', $command, true)) {
            $command[] = $this->url;
        }

        return $command;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function appendYoutubePlayerClient(array $command): array
    {
        if ($this->isYoutubeUrl($this->url) && !\in_array('--extractor-args', $command, true)) {
            $command[] = '--extractor-args';
            $command[] = 'youtube:player_client=tv_samsung,visionos';
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
            'bestaudio' => [...$command, '-f', $this->audioOnlySelector(), '--extract-audio', '--audio-format', 'opus'],
            'best' => [...$command, '-f', $this->bestFormatSelector($outputFormat, $allow4k), '--merge-output-format', $outputFormat],
            'fhd' => [...$command, '-f', $this->fhdFormatSelector($outputFormat), '--merge-output-format', $outputFormat],
            'medium' => [...$command, '-f', $this->mediumFormatSelector($outputFormat), '--merge-output-format', $outputFormat],
            'low' => [...$command, '-f', $this->lowFormatSelector($outputFormat), '--merge-output-format', $outputFormat],
            default => [...$command, '-f', $formatCode, '--merge-output-format', $outputFormat],
        };
    }

    private function bestFormatSelector(string $outputFormat, bool $allow4k): string
    {
        if ($this->audioLanguage !== null) {
            return 'bestvideo+bestaudio[language^=' . $this->audioLanguage . ']/bestvideo+bestaudio/best';
        }

        return self::BEST_FORMAT;
    }

    private function audioOnlySelector(): string
    {
        if ($this->audioLanguage !== null) {
            return 'bestaudio[language^=' . $this->audioLanguage . ']/bestaudio/best';
        }

        return 'bestaudio/best';
    }

    private function fhdFormatSelector(string $outputFormat): string
    {
        return $this->presetFormatSelector(1080, $outputFormat);
    }

    private function mediumFormatSelector(string $outputFormat): string
    {
        return $this->presetFormatSelector(720, $outputFormat);
    }

    private function lowFormatSelector(string $outputFormat): string
    {
        return $this->presetFormatSelector(480, $outputFormat);
    }

    private function presetFormatSelector(int $maxHeight, string $outputFormat): string
    {
        if ($outputFormat === 'mp4') {
            return $this->presetBrowserMp4FormatSelector($maxHeight) . '/' . $this->presetFallbackFormatSelector($maxHeight);
        }

        return $this->presetFallbackFormatSelector($maxHeight);
    }

    private function presetBrowserMp4FormatSelector(int $maxHeight): string
    {
        $h = (string) $maxHeight;
        if ($this->audioLanguage !== null) {
            $lang = $this->audioLanguage;

            return "bestvideo[ext=mp4][vcodec^=avc1][height<={$h}]+bestaudio[language^={$lang}][ext=m4a]/"
                . "bestvideo[ext=mp4][vcodec^=avc1][height<={$h}]+bestaudio[ext=m4a]/"
                . "best[ext=mp4][vcodec^=avc1][acodec^=mp4a][height<={$h}]/"
                . "bestvideo[ext=mp4][height<={$h}]+bestaudio[language^={$lang}][ext=m4a]/"
                . "bestvideo[ext=mp4][height<={$h}]+bestaudio[ext=m4a]/"
                . "best[ext=mp4][height<={$h}]";
        }

        return "bestvideo[ext=mp4][vcodec^=avc1][height<={$h}]+bestaudio[ext=m4a]/"
            . "best[ext=mp4][vcodec^=avc1][acodec^=mp4a][height<={$h}]/"
            . "bestvideo[ext=mp4][height<={$h}]+bestaudio[ext=m4a]/"
            . "best[ext=mp4][height<={$h}]";
    }

    private function presetFallbackFormatSelector(int $maxHeight): string
    {
        return $this->isYoutubeUrl($this->url)
            ? $this->presetNonAv1FormatSelector($maxHeight)
            : $this->presetStandardFormatSelector($maxHeight);
    }

    private function presetNonAv1FormatSelector(int $maxHeight): string
    {
        $h = (string) $maxHeight;
        if ($this->audioLanguage !== null) {
            $lang = $this->audioLanguage;

            return "bestvideo[vcodec!^=av01][height<={$h}]+bestaudio[language^={$lang}]/"
                . "bestvideo[vcodec!^=av01][height<={$h}]+bestaudio/"
                . "best[vcodec!^=av01][height<={$h}]/"
                . "bestvideo[height<={$h}]+bestaudio[language^={$lang}]/"
                . "bestvideo[height<={$h}]+bestaudio/"
                . "best[height<={$h}]";
        }

        return "bestvideo[vcodec!^=av01][height<={$h}]+bestaudio/best[vcodec!^=av01][height<={$h}]/bestvideo[height<={$h}]+bestaudio/best[height<={$h}]";
    }

    private function presetStandardFormatSelector(int $maxHeight): string
    {
        $h = (string) $maxHeight;
        if ($this->audioLanguage !== null) {
            $lang = $this->audioLanguage;

            return "bestvideo[height<={$h}]+bestaudio[language^={$lang}]/"
                . "bestvideo[height<={$h}]+bestaudio/"
                . "best[height<={$h}]";
        }

        return "bestvideo[height<={$h}]+bestaudio/best[height<={$h}]/bestvideo[height<={$h}]+bestaudio/best[height<={$h}]";
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
