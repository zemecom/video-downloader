<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Preview;

use YtdPhp\NativeHost\Store\NativeHostPreviewServerStateStore;
use Throwable;

final readonly class NativeHostPreviewServerService
{
    public function __construct(
        private NativeHostPreviewServerStateStore $stateStore,
        private NativeHostPreviewHttpResponder $responder,
    ) {}

    public function run(): int
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!\is_resource($server)) {
            throw new \RuntimeException($error !== '' ? $error : 'Failed to bind preview server.');
        }

        \stream_set_blocking($server, false);
        $address = (string) \stream_socket_get_name($server, false);
        $separator = \strrpos($address, ':');
        $port = $separator === false ? 0 : (int) \substr($address, $separator + 1);
        $this->stateStore->write((int) \getmypid(), $port);

        /** @var array<int, array{
         *   socket: resource,
         *   requestBuffer: string,
         *   responseReady: bool,
         *   pendingWrite: string,
         *   pendingWriteFromFile: bool,
         *   fileHandle: resource|null,
         *   remainingBytes: int,
         *   done: bool
         * }> $clients
         */
        $clients = [];

        try {
            /** @phpstan-ignore while.alwaysTrue */
            while (true) {
                $read = [$server];
                $write = [];

                foreach ($clients as $clientState) {
                    if (!$clientState['responseReady']) {
                        $read[] = $clientState['socket'];
                    }

                    if ($clientState['responseReady'] && ($clientState['pendingWrite'] !== '' || $clientState['fileHandle'] !== null)) {
                        $write[] = $clientState['socket'];
                    }
                }

                $except = null;
                $readyRead = $read;
                $readyWrite = $write;
                $selected = @\stream_select($readyRead, $readyWrite, $except, null);
                if ($selected === false) {
                    continue;
                }

                foreach ($readyRead as $stream) {
                    if ($stream === $server) {
                        $this->acceptClients($server, $clients);

                        continue;
                    }

                    $clientId = (int) $stream;
                    if (!isset($clients[$clientId])) {
                        continue;
                    }

                    try {
                        $this->readClient($clients[$clientId]);
                    } catch (Throwable $e) {
                        \error_log(\sprintf("[%s] [ERROR] Preview server client read error: %s", \date('Y-m-d H:i:s'), $e->getMessage()));
                        $clients[$clientId]['done'] = true;
                    }
                }

                foreach ($readyWrite as $stream) {
                    $clientId = (int) $stream;
                    if (!isset($clients[$clientId])) {
                        continue;
                    }

                    try {
                        $this->writeClient($clients[$clientId]);
                    } catch (Throwable $e) {
                        \error_log(\sprintf("[%s] [ERROR] Preview server client write error: %s", \date('Y-m-d H:i:s'), $e->getMessage()));
                        $clients[$clientId]['done'] = true;
                    }
                }

                foreach (\array_keys($clients) as $clientId) {
                    if (!($clients[$clientId]['done'] ?? false)) {
                        continue;
                    }

                    $this->closeClient($clients[$clientId]);
                    unset($clients[$clientId]);
                }
            }
        } finally {
            foreach ($clients as $clientState) {
                $this->closeClient($clientState);
            }

            \fclose($server);
            $this->stateStore->clear();
        }

        /** @phpstan-ignore deadCode.unreachable */
        return 0;
    }

    /**
     * @param resource $server
     * @param array<int, array{
     *   socket: resource,
     *   requestBuffer: string,
     *   responseReady: bool,
     *   pendingWrite: string,
     *   pendingWriteFromFile: bool,
     *   fileHandle: resource|null,
     *   remainingBytes: int,
     *   done: bool,
     *   keepAlive: bool
     * }> $clients
     */
    private function acceptClients($server, array &$clients): void
    {
        while (($client = @\stream_socket_accept($server, 0)) !== false) {
            \stream_set_blocking($client, false);
            $clients[(int) $client] = [
                'socket' => $client,
                'requestBuffer' => '',
                'responseReady' => false,
                'pendingWrite' => '',
                'pendingWriteFromFile' => false,
                'fileHandle' => null,
                'remainingBytes' => 0,
                'done' => false,
                'keepAlive' => false,
            ];
        }
    }

    /**
     * @param array{
     *   socket: resource,
     *   requestBuffer: string,
     *   responseReady: bool,
     *   pendingWrite: string,
     *   pendingWriteFromFile: bool,
     *   fileHandle: resource|null,
     *   remainingBytes: int,
     *   done: bool,
     *   keepAlive: bool
     * } $clientState
     */
    private function readClient(array &$clientState): void
    {
        if ($clientState['responseReady']) {
            return;
        }

        $chunk = \stream_get_contents($clientState['socket']);
        if (!\is_string($chunk) || $chunk === '') {
            if (\feof($clientState['socket'])) {
                $clientState['done'] = true;
            }

            return;
        }

        $clientState['requestBuffer'] .= $chunk;
        if (!\str_contains($clientState['requestBuffer'], "\r\n\r\n") && !\str_contains($clientState['requestBuffer'], "\n\n")) {
            return;
        }

        $request = $this->parseRequest($clientState['requestBuffer']);
        $protocol = $request['protocol'] ?? 'HTTP/1.1';
        $connHeader = \strtolower($request['headers']['connection'] ?? '');
        $clientState['keepAlive'] = $connHeader === 'keep-alive' || ($connHeader !== 'close' && $protocol === 'HTTP/1.1');

        $response = $this->responder->respond($request['method'], $request['target'], $request['headers'], false);

        $clientState['responseReady'] = true;
        $clientState['pendingWrite'] = $this->formatResponseHead($response, $clientState['keepAlive']);

        if (\strtoupper($request['method']) !== 'GET' || !\is_string($response['filePath'] ?? null)) {
            if (\is_string($response['body'] ?? null) && $response['body'] !== '') {
                $clientState['pendingWrite'] .= $response['body'];
            }

            return;
        }

        $fileHandle = \fopen($response['filePath'], 'rb');
        if (!\is_resource($fileHandle)) {
            $clientState['done'] = true;

            return;
        }

        \fseek($fileHandle, (int) ($response['rangeStart'] ?? 0));
        $clientState['fileHandle'] = $fileHandle;
        $clientState['remainingBytes'] = (int) ($response['rangeLength'] ?? 0);
    }

    /**
     * @param array{
     *   socket: resource,
     *   requestBuffer: string,
     *   responseReady: bool,
     *   pendingWrite: string,
     *   pendingWriteFromFile: bool,
     *   fileHandle: resource|null,
     *   remainingBytes: int,
     *   done: bool,
     *   keepAlive: bool
     * } $clientState
     */
    private function writeClient(array &$clientState): void
    {
        if (!$clientState['responseReady']) {
            return;
        }

        if ($clientState['pendingWrite'] === '' && \is_resource($clientState['fileHandle']) && $clientState['remainingBytes'] > 0) {
            $chunk = \stream_get_contents($clientState['fileHandle'], \min(8192, $clientState['remainingBytes']));
            if (!\is_string($chunk) || $chunk === '') {
                \fclose($clientState['fileHandle']);
                $clientState['fileHandle'] = null;
                $clientState['remainingBytes'] = 0;
            } else {
                $clientState['pendingWrite'] = $chunk;
                $clientState['pendingWriteFromFile'] = true;
            }
        }

        if ($clientState['pendingWrite'] === '') {
            if ($clientState['fileHandle'] === null || $clientState['remainingBytes'] <= 0) {
                $clientState['done'] = true;
            }

            return;
        }

        $written = @\fwrite($clientState['socket'], $clientState['pendingWrite']);
        if ($written === false) {
            $clientState['done'] = true;

            return;
        }

        if ($written === 0) {
            if (\feof($clientState['socket'])) {
                $clientState['done'] = true;
            }

            return;
        }

        $clientState['pendingWrite'] = \substr($clientState['pendingWrite'], $written);
        if ($clientState['pendingWriteFromFile']) {
            $clientState['remainingBytes'] = \max(0, $clientState['remainingBytes'] - $written);

            if ($clientState['pendingWrite'] === '') {
                $clientState['pendingWriteFromFile'] = false;
                if ($clientState['remainingBytes'] <= 0 && \is_resource($clientState['fileHandle'])) {
                    \fclose($clientState['fileHandle']);
                    $clientState['fileHandle'] = null;
                }
            }
        }

        if ($clientState['pendingWrite'] === '' && $clientState['fileHandle'] === null && $clientState['remainingBytes'] <= 0) {
            if ($clientState['keepAlive']) {
                $clientState['responseReady'] = false;
                $clientState['requestBuffer'] = '';
            } else {
                $clientState['done'] = true;
            }
        }
    }

    /**
     * @param array{
     *   socket: resource,
     *   requestBuffer: string,
     *   responseReady: bool,
     *   pendingWrite: string,
     *   pendingWriteFromFile: bool,
     *   fileHandle: resource|null,
     *   remainingBytes: int,
     *   done: bool,
     *   keepAlive: bool
     * } $clientState
     */
    private function closeClient(array $clientState): void
    {
        if (\is_resource($clientState['fileHandle'])) {
            \fclose($clientState['fileHandle']);
        }

        if (\is_resource($clientState['socket'])) {
            \fclose($clientState['socket']);
        }
    }

    /**
     * @return array{method:string,target:string,headers:array<string,string>}
     */
    private function parseRequest(string $buffer): array
    {
        $headerBlock = \explode("\r\n\r\n", $buffer, 2)[0];
        if ($headerBlock === $buffer) {
            $headerBlock = \explode("\n\n", $buffer, 2)[0];
        }

        $lines = \preg_split("/\r\n|\n|\r/", $headerBlock) ?: [];
        $requestLine = \trim((string) array_shift($lines));
        $parts = \explode(' ', $requestLine, 3);
        $method = $parts[0] ?? 'GET';
        $target = $parts[1] ?? '/';
        $protocol = $parts[2] ?? 'HTTP/1.1';
        $headers = [];

        foreach ($lines as $line) {
            $trimmed = \trim($line);
            if ($trimmed === '') {
                continue;
            }

            $headerParts = \explode(':', $trimmed, 2);
            if (count($headerParts) !== 2) {
                continue;
            }

            $headers[strtolower(\trim($headerParts[0]))] = \trim($headerParts[1]);
        }

        return [
            'method' => $method,
            'target' => $target,
            'protocol' => $protocol,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function formatResponseHead(array $response, bool $keepAlive): string
    {
        $status = (int) ($response['status'] ?? 500);
        $headers = \is_array($response['headers'] ?? null) ? $response['headers'] : [];
        $head = \sprintf("HTTP/1.1 %d %s\r\n", $status, $this->reasonPhrase($status));
        $head .= $keepAlive ? "Connection: keep-alive\r\n" : "Connection: close\r\n";

        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }

        return $head . "\r\n";
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
