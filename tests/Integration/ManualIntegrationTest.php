<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ManualIntegrationTest extends TestCase
{
    public function testManualSuiteIsOptIn(): void
    {
        $this->markTestSkipped('Manual integration suite. Use real TEST_* URLs and run explicitly.');
    }
}
