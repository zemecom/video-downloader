<?php

declare(strict_types=1);

namespace YtdPhp\Runtime;

use Normalizer;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;

final readonly class RuntimeBootstrap
{
    public const string LOCAL_PROXY_RULES_FILE = 'proxy_rules.yaml';

    private const array RUNTIME_MARKERS = ['.env', self::LOCAL_PROXY_RULES_FILE, 'proxy_rules.example.yaml'];

    private const string INSTALL_PROJECT_DIRNAME = 'project';

    /** @var array<string, string> */
    private const array MULTI_SHORT_REPLACEMENTS = [
        '-np' => '--no-proxy',
        '-dr' => '--dry-run',
        '-nps' => '--no-playlist-sizes',
        '-cd' => '--concurrent-downloads',
        '-cf' => '--concurrent-fragments',
        '-dc' => '--doctor',
    ];

    public function __construct(
        private ?string $packageRoot = null,
    ) {}

    public function getPackageRoot(): string
    {
        $root = $this->packageRoot ?? \dirname(__DIR__, 2);

        return $this->normalizePath($root);
    }

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    public function normalizeArgv(array $argv): array
    {
        $normalized = [];
        foreach ($argv as $token) {
            $normalized[] = self::MULTI_SHORT_REPLACEMENTS[$token] ?? $token;
        }

        return \array_values($normalized);
    }

    public function normalizeGlobalArgv(): void
    {
        $argv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
        if (!is_array($argv)) {
            return;
        }

        $normalized = $this->normalizeArgv($argv);
        $_SERVER['argv'] = $normalized;
        $_SERVER['argc'] = \count($normalized);
        $GLOBALS['argv'] = $normalized;
        $GLOBALS['argc'] = \count($normalized);
    }

    public function ensureProjectRoot(): string
    {
        $projectRoot = $this->getProjectRoot();
        if (\getenv('YTD_PROJECT_ROOT') === false) {
            $this->setEnvValue('YTD_PROJECT_ROOT', $projectRoot);
        }

        return $projectRoot;
    }

    public function getProjectRoot(): string
    {
        $configuredRoot = \getenv('YTD_PROJECT_ROOT');
        if (\is_string($configuredRoot) && $configuredRoot !== '') {
            return $this->normalizePath($configuredRoot);
        }

        return $this->discoverRuntimeRoot();
    }

    public function initializeEnvironment(): void
    {
        $this->loadEnvFile('.env');
    }

    public function loadEnvFile(string $filename): void
    {
        $envPath = $this->getProjectRoot() . DIRECTORY_SEPARATOR . $filename;
        if (!\is_file($envPath)) {
            return;
        }

        foreach ($this->readEnvFileValues($envPath) as $key => $value) {
            if (\getenv($key) === false) {
                $this->setEnvValue($key, $value);
            }
        }
    }

    public function getProxyEpilog(): string
    {
        $proxy = \getenv('PROXY_LOCAL');

        return \is_string($proxy) && $proxy !== '' ? $proxy : 'Не задан';
    }

    public function getProxyRulesPath(): string
    {
        return $this->resolveRuntimePath(
            'PROXY_RULES_FILE',
            self::LOCAL_PROXY_RULES_FILE,
        );
    }

    public function getErrorLogPath(): string
    {
        return $this->resolveRuntimePath(
            'YTD_ERROR_LOG_FILE',
            'logs' . DIRECTORY_SEPARATOR . 'errors.log',
        );
    }

    public function getNativeHostLogPath(): string
    {
        return $this->resolveRuntimePath(
            'YTD_NATIVE_HOST_LOG_FILE',
            'logs' . DIRECTORY_SEPARATOR . 'native-host.log',
        );
    }

    public function getNativeHostJobsDirectoryPath(): string
    {
        return $this->resolveRuntimePath(
            'YTD_NATIVE_HOST_JOBS_DIR',
            'logs' . DIRECTORY_SEPARATOR . 'native-host-jobs',
        );
    }

    public function getNativeHostRecentDownloadsPath(): string
    {
        $legacyConfiguredPath = \getenv('YTD_NATIVE_HOST_RECENT_DOWNLOADS_FILE');
        if (\is_string($legacyConfiguredPath) && $legacyConfiguredPath !== '' && $this->looksLikeSqlitePath($legacyConfiguredPath)) {
            return $this->resolveConfiguredPath($legacyConfiguredPath);
        }

        return $this->resolveRuntimePath(
            'YTD_NATIVE_HOST_RECENT_DOWNLOADS_DB_FILE',
            'chrome-ext' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'native-host-recent-downloads.sqlite',
        );
    }

    public function getLegacyNativeHostRecentDownloadsPath(): string
    {
        $legacyConfiguredPath = \getenv('YTD_NATIVE_HOST_RECENT_DOWNLOADS_FILE');
        if (\is_string($legacyConfiguredPath) && $legacyConfiguredPath !== '' && !$this->looksLikeSqlitePath($legacyConfiguredPath)) {
            return $this->resolveConfiguredPath($legacyConfiguredPath);
        }

        return $this->resolveRuntimePath(
            'YTD_NATIVE_HOST_RECENT_DOWNLOADS_LEGACY_FILE',
            'chrome-ext' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'native-host-recent-downloads.json',
        );
    }

    public function getNativeHostPreviewRegistryPath(): string
    {
        return $this->resolveRuntimePath(
            'YTD_NATIVE_HOST_PREVIEW_REGISTRY_FILE',
            'logs' . DIRECTORY_SEPARATOR . 'native-host-preview-registry.json',
        );
    }

    public function getNativeHostPreviewServerStatePath(): string
    {
        return $this->resolveRuntimePath(
            'YTD_NATIVE_HOST_PREVIEW_SERVER_STATE_FILE',
            'logs' . DIRECTORY_SEPARATOR . 'native-host-preview-server.json',
        );
    }

    public function getNativeHostPreviewServerLogPath(): string
    {
        return $this->resolveRuntimePath(
            'YTD_NATIVE_HOST_PREVIEW_SERVER_LOG_FILE',
            'logs' . DIRECTORY_SEPARATOR . 'native-host-preview-server.log',
        );
    }

    public function getDownloadBasePath(string $videoUrl, ?string $overridePath = null): string
    {
        if (\is_string($overridePath) && \trim($overridePath) !== '') {
            return $this->expandPath(\trim($overridePath));
        }

        $envPath = $this->isYoutubeUrl($videoUrl)
            ? (\getenv('DOWNLOAD_DIR_YOUTUBE') ?: '~/Movies/Downloaded/Youtube')
            : (\getenv('DOWNLOAD_DIR_GENERAL') ?: '~/Movies/Downloaded');

        return $this->expandPath((string) $envPath);
    }

    public function getDefaultOutputFormat(): string
    {
        $format = \getenv('OUTPUT_FORMAT');

        return $this->normalizeOutputFormat(\is_string($format) ? $format : null);
    }

    public function normalizeOutputFormat(?string $format): string
    {
        $normalized = \strtolower(\trim((string) $format));

        return \in_array($normalized, ['mkv', 'mp4'], true) ? $normalized : 'mkv';
    }

    public function getConcurrentDownloads(): int
    {
        $value = \getenv('CONCURRENT_DOWNLOADS');

        return \max(1, (int) (\is_string($value) && $value !== '' ? $value : '1'));
    }

    public function getConcurrentFragments(): int
    {
        $value = \getenv('CONCURRENT_FRAGMENTS');

        return \max(1, (int) (\is_string($value) && $value !== '' ? $value : '20'));
    }

    public function getProgressDelta(): string
    {
        $value = \trim((string) (\getenv('YTD_PROGRESS_DELTA') ?: ''));
        if ($value === '') {
            return '0.5';
        }

        return is_numeric($value) && (float) $value > 0 ? $value : '0.5';
    }

    public function shouldUseProgressNewline(): bool
    {
        $value = \getenv('YTD_PROGRESS_NEWLINE');
        if (!\is_string($value)) {
            return false;
        }

        $normalized = \strtolower(\trim($value));

        return !in_array($normalized, ['', '0', 'false', 'no'], true);
    }

    public function isYoutubeUrl(string $videoUrl): bool
    {
        $hostname = \strtolower((string) (parse_url($videoUrl, PHP_URL_HOST) ?? ''));
        if ($hostname === '') {
            $hostname = \strtolower((string) (parse_url('//' . \ltrim($videoUrl, '/'), PHP_URL_HOST) ?? ''));
        }

        return $hostname === 'youtube.com'
            || $hostname === 'youtu.be'
            || str_ends_with($hostname, '.youtube.com')
            || str_ends_with($hostname, '.youtu.be');
    }

    public function looksLikePlaylistUrl(string $videoUrl): bool
    {
        $parts = parse_url($videoUrl);
        $path = \strtolower((string) ($parts['path'] ?? ''));

        return \str_contains($path, '/playlist')
            || \str_contains($path, '/playlists')
            || \str_contains($path, '/plst/')
            || str_ends_with($path, '/plst');
    }

    public function formatProxyForDisplay(string $proxyUrl): string
    {
        if (\str_contains($proxyUrl, '@')) {
            $parts = \explode('@', $proxyUrl);

            return end($parts);
        }

        if (\str_contains($proxyUrl, '://')) {
            $parts = \explode('://', $proxyUrl, 2);

            return $parts[1];
        }

        return $proxyUrl;
    }

    public function sanitizePathComponent(string $value, string $fallback = 'playlist'): string
    {
        $normalized = \trim($value);
        if (\class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($normalized, Normalizer::FORM_C) ?: $normalized;
        }

        return $this->sanitizeTerminalLinkPathComponent($normalized, $fallback);
    }

    public function sanitizeOutputFilename(string $path): string
    {
        $slashPos = \strrpos($path, '/');
        $backslashPos = \strrpos($path, '\\');

        $separatorOffset = match (true) {
            $slashPos !== false && $backslashPos !== false => \max($slashPos, $backslashPos),
            $slashPos !== false => $slashPos,
            $backslashPos !== false => $backslashPos,
            default => false,
        };

        $directory = $separatorOffset === false
            ? ''
            : \substr($path, 0, $separatorOffset + 1);
        $filename = $separatorOffset === false
            ? $path
            : \substr($path, $separatorOffset + 1);

        if (\class_exists(Normalizer::class)) {
            $filename = Normalizer::normalize($filename, Normalizer::FORM_C) ?: $filename;
        }
        $filename = $this->sanitizeTerminalLinkFilename($filename);

        return $directory . $filename;
    }

    private function sanitizeTerminalLinkFilename(string $filename): string
    {
        $extensionOffset = \strrpos($filename, '.');
        if (
            in_array($extensionOffset, [false, 0, \strlen($filename) - 1], true)
        ) {
            return $this->sanitizeTerminalLinkPathComponent($filename, 'download');
        }

        $stem = \substr($filename, 0, $extensionOffset);
        $extension = \substr($filename, $extensionOffset + 1);
        $safeStem = $this->sanitizeTerminalLinkPathComponent($stem, 'download');
        $safeExtension = $this->sanitizeFilenameExtension($extension);

        return $safeExtension !== '' ? $safeStem . '.' . $safeExtension : $safeStem;
    }

    private function sanitizeTerminalLinkPathComponent(string $value, string $fallback): string
    {
        $normalized = \preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $value);
        $normalized = \trim((string) $normalized, '._-');

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function sanitizeFilenameExtension(string $extension): string
    {
        $normalized = \preg_replace('/[^A-Za-z0-9]+/', '_', $extension);

        return \trim((string) $normalized, '._-');
    }

    /**
     * @return array<string, string>
     */
    public function readKeyValueFile(string $path): array
    {
        return $this->readEnvFileValues($path);
    }

    /**
     * @return array<string, string>
     */
    public function readEnvFileValues(string $path): array
    {
        $values = [];
        $contents = @\file_get_contents($path);
        if (!\is_string($contents) || $contents === '') {
            return $values;
        }

        try {
            $parsed = new Dotenv()->parse($contents, $path);
        } catch (FormatException) {
            return $values;
        }

        foreach ($parsed as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    private function discoverRuntimeRoot(): string
    {
        $seen = [];
        $startDirs = [
            getcwd() ?: null,
            $this->resolveCommandDir($_SERVER['argv'][0] ?? null),
            $this->resolveCommandDir(PHP_BINARY),
            $this->getPackageRoot(),
        ];

        foreach ($startDirs as $startDir) {
            foreach ($this->iterCandidateRoots($startDir) as $candidate) {
                if (isset($seen[$candidate])) {
                    continue;
                }
                $seen[$candidate] = true;

                if ($this->hasRuntimeMarker($candidate)) {
                    return $candidate;
                }
            }
        }

        return $this->getPackageRoot();
    }

    private function resolveCommandDir(?string $path): ?string
    {
        if (!\is_string($path) || $path === '') {
            return null;
        }

        $expandedPath = $this->expandPath($path);
        if ($this->isAbsolutePath($expandedPath) || \str_contains($expandedPath, DIRECTORY_SEPARATOR)) {
            return \dirname($this->normalizePath($expandedPath));
        }

        return null;
    }

    private function resolveRuntimePath(string $envKey, string $defaultRelativePath): string
    {
        $configuredPath = \getenv($envKey);
        if (!\is_string($configuredPath) || $configuredPath === '') {
            return $this->normalizePath($this->getProjectRoot() . DIRECTORY_SEPARATOR . $defaultRelativePath);
        }

        return $this->resolveConfiguredPath($configuredPath);
    }

    private function resolveConfiguredPath(string $configuredPath): string
    {
        if ($configuredPath === '') {
            return $this->getProjectRoot();
        }

        $expandedPath = $this->expandPath($configuredPath);
        if ($this->isAbsolutePath($expandedPath)) {
            return $this->normalizePath($expandedPath);
        }

        return $this->normalizePath($this->getProjectRoot() . DIRECTORY_SEPARATOR . $expandedPath);
    }

    private function looksLikeSqlitePath(string $path): bool
    {
        $normalizedPath = \strtolower($path);

        return \str_ends_with($normalizedPath, '.sqlite')
            || \str_ends_with($normalizedPath, '.sqlite3')
            || \str_ends_with($normalizedPath, '.db');
    }

    /**
     * @return list<string>
     */
    private function iterCandidateRoots(?string $startDir): array
    {
        if (!\is_string($startDir) || $startDir === '') {
            return [];
        }

        $roots = [];
        $current = $this->normalizePath($startDir);
        while (true) {
            $roots[] = $current;

            $installProjectDir = $current . DIRECTORY_SEPARATOR . self::INSTALL_PROJECT_DIRNAME;
            if (\is_dir($installProjectDir)) {
                $roots[] = $this->normalizePath($installProjectDir);
            }

            $parent = \dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        return $roots;
    }

    private function hasRuntimeMarker(string $path): bool
    {
        return array_any(self::RUNTIME_MARKERS, fn($marker): bool => \is_file($path . DIRECTORY_SEPARATOR . $marker));
    }

    private function normalizePath(string $path): string
    {
        $expandedPath = $this->expandPath($path);
        $realPath = \realpath($expandedPath);

        return $realPath !== false ? $realPath : $expandedPath;
    }

    private function expandPath(string $path): string
    {
        if (\str_starts_with($path, '~')) {
            $home = \getenv('HOME');
            if (\is_string($home) && $home !== '') {
                $suffix = \ltrim(\substr($path, 1), DIRECTORY_SEPARATOR);

                return $suffix === '' ? $home : $home . DIRECTORY_SEPARATOR . $suffix;
            }
        }

        return $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return \str_starts_with($path, DIRECTORY_SEPARATOR) || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }

    private function setEnvValue(string $key, string $value): void
    {
        \putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
