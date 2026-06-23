<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

use YtdPhp\Exception\NativeHostException;

use const FILTER_VALIDATE_URL;

final readonly class NativeHostRequest
{
    private const string LEGACY_DOWNLOAD_CURRENT_TAB = 'download_current_tab';
    public const string MODE_VIDEO = 'video';
    public const string MODE_AUDIO = 'audio';
    public const string START_DOWNLOAD = 'start_download';
    public const string GET_JOB_STATUS = 'get_job_status';
    public const string CANCEL_DOWNLOAD = 'cancel_download';
    public const string LIST_RECENT_DOWNLOADS = 'list_recent_downloads';
    public const string PREVIEW_RECENT_DOWNLOAD = 'preview_recent_download';
    public const string OPEN_RECENT_DOWNLOAD = 'open_recent_download';
    public const string REVEAL_RECENT_DOWNLOAD = 'reveal_recent_download';
    public const string DELETE_RECENT_DOWNLOAD = 'delete_recent_download';

    public function __construct(
        public string $action,
        public ?string $url = null,
        public ?string $jobId = null,
        public ?string $mode = null,
        public ?string $entryId = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $action = $payload['action'] ?? null;
        $url = $payload['url'] ?? null;
        $jobId = $payload['jobId'] ?? null;
        $mode = $payload['mode'] ?? null;
        $entryId = $payload['entryId'] ?? null;

        if ($action === self::LEGACY_DOWNLOAD_CURRENT_TAB) {
            $action = self::START_DOWNLOAD;
        }

        if (!\is_string($action) || $action === '') {
            throw new NativeHostException('invalid_payload', 'Invalid native host payload.');
        }

        return match ($action) {
            self::START_DOWNLOAD => new self($action, self::validateUrl($url), null, self::validateMode($mode)),
            self::GET_JOB_STATUS, self::CANCEL_DOWNLOAD => new self($action, null, self::validateJobId($jobId), null),
            self::LIST_RECENT_DOWNLOADS => new self($action),
            self::PREVIEW_RECENT_DOWNLOAD, self::OPEN_RECENT_DOWNLOAD, self::REVEAL_RECENT_DOWNLOAD, self::DELETE_RECENT_DOWNLOAD => new self($action, null, null, null, self::validateEntryId($entryId)),
            default => throw new NativeHostException('invalid_payload', 'Invalid native host payload.'),
        };
    }

    private static function validateUrl(mixed $url): string
    {
        if (!\is_string($url) || $url === '') {
            throw new NativeHostException('invalid_payload', 'Invalid native host payload.');
        }

        if (\filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new NativeHostException('invalid_url', 'Invalid URL.');
        }

        $scheme = \strtolower((string) (\parse_url($url, PHP_URL_SCHEME) ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new NativeHostException('unsupported_page', 'Unsupported page URL.');
        }

        return $url;
    }

    private static function validateJobId(mixed $jobId): string
    {
        if (!\is_string($jobId) || $jobId === '' || preg_match('/^[A-Za-z0-9._-]+$/', $jobId) !== 1) {
            throw new NativeHostException('invalid_payload', 'Invalid native host payload.');
        }

        return $jobId;
    }

    private static function validateMode(mixed $mode): string
    {
        if ($mode === null) {
            return self::MODE_VIDEO;
        }

        if (!\is_string($mode) || !\in_array($mode, [self::MODE_VIDEO, self::MODE_AUDIO], true)) {
            throw new NativeHostException('invalid_payload', 'Invalid native host payload.');
        }

        return $mode;
    }

    private static function validateEntryId(mixed $entryId): string
    {
        if (!\is_string($entryId) || $entryId === '' || preg_match('/^[A-Za-z0-9._-]+$/', $entryId) !== 1) {
            throw new NativeHostException('invalid_payload', 'Invalid native host payload.');
        }

        return $entryId;
    }
}
