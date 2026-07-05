<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Download\YtDlp\YtDlpClient;
use YtdPhp\Runtime\ProcessEnvironment;

final class YtDlpClientTest extends TestCase
{
    public function testStaticProcessEnvironmentMethodsRemainBackwardCompatibleDelegates(): void
    {
        self::assertSame(ProcessEnvironment::build(), YtDlpClient::buildProcessEnv());
        self::assertSame(ProcessEnvironment::buildAugmentedPath(), YtDlpClient::buildAugmentedPath());
    }
}
