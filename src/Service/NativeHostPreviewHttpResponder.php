<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use function array_change_key_case;
use function explode;
use function filesize;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function parse_str;
use function parse_url;
use function pathinfo;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function strlen;
use function strtolower;
use function strtoupper;

use const PATHINFO_EXTENSION;

final readonly class NativeHostPreviewHttpResponder
{
    public function __construct(
        private NativeHostPreviewRegistryService $registry,
    ) {}

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function respond(string $method, string $target, array $headers, bool $includeBody = true): array
    {
        $normalizedMethod = strtoupper($method);
        $normalizedHeaders = array_change_key_case($headers, CASE_LOWER);

        if ($normalizedMethod !== 'GET' && $normalizedMethod !== 'HEAD') {
            return $this->response(405, ['Allow' => 'GET, HEAD'], 'Method not allowed.');
        }

        $parts = parse_url($target);
        $path = $parts['path'] ?? '/';
        if (!is_string($path)) {
            return $this->response(404, [], 'Not found.');
        }

        if ($path === '/healthz') {
            return $this->response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $normalizedMethod === 'HEAD' ? '' : 'ok');
        }

        if (preg_match('#^/preview/(?P<jobId>[^/?]+)$#', $path, $matches) !== 1) {
            return $this->response(404, [], 'Not found.');
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $token = $query['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return $this->response(404, [], 'Not found.');
        }

        $entry = $this->registry->resolve(rawurldecode((string) $matches['jobId']), $token);
        if (!is_array($entry)) {
            return $this->response(404, [], 'Not found.');
        }

        $filePath = $entry['path'] ?? null;
        if (!is_string($filePath) || $filePath === '') {
            return $this->response(404, [], 'Not found.');
        }

        $size = filesize($filePath);
        if (!is_int($size) || $size < 0) {
            return $this->response(404, [], 'Not found.');
        }

        $range = $this->parseRange($normalizedHeaders['range'] ?? null, $size);
        if ($range === null && isset($normalizedHeaders['range'])) {
            return $this->response(416, [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => sprintf('bytes */%d', $size),
            ], '');
        }

        $start = $range['start'] ?? 0;
        $end = $range['end'] ?? max(0, $size - 1);
        $length = $size === 0 ? 0 : ($end - $start + 1);
        $status = $range === null ? 200 : 206;

        $responseHeaders = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store',
            'Content-Length' => (string) $length,
            'Content-Type' => $this->guessContentType($filePath),
        ];

        if ($status === 206) {
            $responseHeaders['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        $body = '';
        if ($normalizedMethod === 'GET' && $includeBody && $length > 0) {
            $body = (string) file_get_contents($filePath, false, null, $start, $length);
        }

        return $this->response($status, $responseHeaders, $normalizedMethod === 'HEAD' ? '' : $body, [
            'filePath' => $filePath,
            'rangeStart' => $start,
            'rangeLength' => $length,
        ]);
    }

    /**
     * @return array{start:int, end:int}|null
     */
    private function parseRange(mixed $rangeHeader, int $size): ?array
    {
        if (!is_string($rangeHeader) || $rangeHeader === '') {
            return null;
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches) !== 1) {
            return null;
        }

        $startPart = $matches[1];
        $endPart = $matches[2];
        if ($size === 0) {
            return ['start' => 0, 'end' => -1];
        }

        if ($startPart === '' && $endPart === '') {
            return null;
        }

        if ($startPart === '') {
            $suffixLength = (int) $endPart;
            if ($suffixLength <= 0) {
                return null;
            }

            $length = min($suffixLength, $size);

            return [
                'start' => $size - $length,
                'end' => $size - 1,
            ];
        }

        $start = (int) $startPart;
        if ($start < 0 || $start >= $size) {
            return null;
        }

        $end = $endPart === ''
            ? $size - 1
            : (int) $endPart;

        if ($end < $start) {
            return null;
        }

        return [
            'start' => $start,
            'end' => min($end, $size - 1),
        ];
    }

    private function guessContentType(string $path): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4', 'm4v' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function response(int $status, array $headers, string $body, array $extra = []): array
    {
        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            ...$extra,
        ];
    }
}
