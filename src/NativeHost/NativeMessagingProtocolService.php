<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost;

use JsonException;
use YtdPhp\NativeHost\NativeHostException;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class NativeMessagingProtocolService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        try {
            $body = \json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new NativeHostException('unexpected_error', 'Failed to encode native host message.');
        }

        return \pack('L', \strlen($body)) . $body;
    }

    public function readFromString(string $message): array
    {
        if (\strlen($message) < 4) {
            throw new NativeHostException('invalid_payload', 'Native host payload is too short.');
        }

        $header = \unpack('Llength', \substr($message, 0, 4));
        $length = (int) ($header['length'] ?? 0);
        $body = \substr($message, 4);

        if (\strlen($body) !== $length) {
            throw new NativeHostException('invalid_payload', 'Native host payload length mismatch.');
        }

        try {
            $decoded = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new NativeHostException('invalid_payload', 'Native host payload is not valid JSON.');
        }

        if (!\is_array($decoded)) {
            throw new NativeHostException('invalid_payload', 'Native host payload must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param resource $stream
     * @return array<string, mixed>
     */
    public function readFromStream($stream): array
    {
        if (!\is_resource($stream)) {
            throw new NativeHostException('invalid_payload', 'Native host input stream is not available.');
        }

        $header = \fread($stream, 4);
        if ($header === false || \strlen($header) !== 4) {
            throw new NativeHostException('invalid_payload', 'Missing native host message header.');
        }

        $length = (int) ((\unpack('Llength', $header)['length'] ?? 0));
        $body = '';
        while (\strlen($body) < $length) {
            $chunk = \fread($stream, $length - \strlen($body));
            if ($chunk === false || $chunk === '') {
                throw new NativeHostException('invalid_payload', 'Unexpected end of native host input.');
            }

            $body .= $chunk;
        }

        return $this->readFromString($header . $body);
    }

    /**
     * @param resource $stream
     * @param array<string, mixed> $payload
     */
    public function writeToStream($stream, array $payload): void
    {
        if (!\is_resource($stream)) {
            throw new NativeHostException('unexpected_error', 'Native host output stream is not available.');
        }

        fwrite($stream, $this->encode($payload));
    }
}
