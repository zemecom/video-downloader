<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Runtime\ProcessEnvironment;

final class ProcessEnvironmentTest extends TestCase
{
    public function testBuildAugmentedPathPrependsCommonBinaryLocations(): void
    {
        $previousPath = \getenv('PATH');
        $previousHome = \getenv('HOME');
        \putenv('PATH=/usr/bin:/bin');
        \putenv('HOME=/Users/example');

        try {
            $path = ProcessEnvironment::buildAugmentedPath();

            self::assertStringContainsString('/Users/example/.local/bin', $path);
            self::assertStringContainsString('/opt/homebrew/bin', $path);
            self::assertStringContainsString('/usr/local/bin', $path);
            self::assertStringContainsString('/usr/bin', $path);
        } finally {
            if ($previousPath === false) {
                \putenv('PATH');
            } else {
                \putenv('PATH=' . $previousPath);
            }

            if ($previousHome === false) {
                \putenv('HOME');
            } else {
                \putenv('HOME=' . $previousHome);
            }
        }
    }
}
