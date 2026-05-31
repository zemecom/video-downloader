<?php

declare(strict_types=1);

namespace YtdPhp\Service;

use Throwable;

use function explode;
use function fclose;
use function fgets;
use function fwrite;
use function getmypid;
use function is_array;
use function is_resource;
use function stream_get_meta_data;
use function stream_get_contents;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function trim;

final readonly class NativeHostPreviewServerService
{
    public function __construct(
        private NativeHostPreviewServerStateStore $stateStore,
        private NativeHostPreviewHttpResponder $responder,
    ) {}

    public function run(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!is_resource($server)) {
            throw new \RuntimeException($error !== '' ? $error : 'Failed to bind preview server.');
        }

        $address = (string) stream_socket_get_name($server, false);
        $separator = strrpos($address, ':');
        $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
        $this->stateStore->write((int) getmypid(), $port);

        try {
            while (($client = @stream_socket_accept($server, -1)) !== false) {
                try {
                    $this->handleClient($client);
                } catch (Throwable) {
                    // Best-effort server for local previews: drop malformed requests.
                } finally {
                    fclose($client);
                }
            }
        } finally {
            fclose($server);
            $this->stateStore->clear();
        }

        return 0;
    }

    /**
     * @param resource $client
     */
    private function handleClient($client): void
    {
        $requestLine = fgets($client);
        if (!is_string($requestLine) || trim($requestLine) === '') {
            return;
        }

        $parts = explode(' ', trim($requestLine), 3);
        $method = $parts[0] ?? 'GET';
        $target = $parts[1] ?? '/';
        $headers = [];

        while (($line = fgets($client)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                break;
            }

            $headerParts = explode(':', $trimmed, 2);
            if (count($headerParts) !== 2) {
                continue;
            }

            $headers[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
        }

        $response = $this->responder->respond($method, $target, $headers, false);
        $this->writeResponse($client, $method, $response);
    }

    /**
     * @param resource $client
     * @param array<string, mixed> $response
     */
    private function writeResponse($client, string $method, array $response): void
    {
        $status = (int) ($response['status'] ?? 500);
        $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
        fwrite($client, sprintf("HTTP/1.1 %d %s\r\n", $status, $this->reasonPhrase($status)));
        fwrite($client, "Connection: close\r\n");

        foreach ($headers as $name => $value) {
            fwrite($client, $name . ': ' . $value . "\r\n");
        }

        fwrite($client, "\r\n");

        if (strtoupper($method) !== 'GET' || !is_string($response['filePath'] ?? null)) {
            if (is_string($response['body'] ?? null) && $response['body'] !== '') {
                fwrite($client, $response['body']);
            }

            return;
        }

        $this->streamFile(
            $client,
            (string) $response['filePath'],
            (int) ($response['rangeStart'] ?? 0),
            (int) ($response['rangeLength'] ?? 0),
        );
    }

    /**
     * @param resource $client
     */
    private function streamFile($client, string $path, int $offset, int $length): void
    {
        if ($length <= 0) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            return;
        }

        try {
            fseek($handle, $offset);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = stream_get_contents($handle, min(8192, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }

                $remaining -= strlen($chunk);
                fwrite($client, $chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            206 => 'Partial Content',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            416 => 'Range Not Satisfiable',
            default => 'Internal Server Error',
        };
    }
}
