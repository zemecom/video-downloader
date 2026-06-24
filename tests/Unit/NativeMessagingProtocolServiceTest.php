<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\NativeHost\NativeMessagingProtocolService;

final class NativeMessagingProtocolServiceTest extends TestCase
{
    public function testEncodePrefixesJsonPayloadWithNativeLengthHeader(): void
    {
        $service = new NativeMessagingProtocolService();

        $encoded = $service->encode([
            'ok' => true,
            'code' => 'accepted',
        ]);

        $decodedHeader = \unpack('Llength', \substr($encoded, 0, 4));
        $body = \substr($encoded, 4);

        self::assertSame(\strlen($body), $decodedHeader['length']);
        self::assertSame(
            \json_encode(['ok' => true, 'code' => 'accepted'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $body,
        );
    }

    public function testReadFromStringDecodesNativeMessagePayload(): void
    {
        $service = new NativeMessagingProtocolService();
        $payload = ['action' => 'download_current_tab', 'url' => 'https://example.com/video'];

        self::assertSame($payload, $service->readFromString($service->encode($payload)));
    }
}
