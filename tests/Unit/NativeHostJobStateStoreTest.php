<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Bootstrap\RuntimeBootstrap;
use YtdPhp\Service\NativeHostJobStateStore;

final class NativeHostJobStateStoreTest extends TestCase
{
    public function testWriteAndReadRoundTripStatePayload(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_state_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostJobStateStore(new RuntimeBootstrap($root));
            $state = [
                'jobId' => 'job-123',
                'status' => 'downloading',
                'progressPercent' => 48.5,
                'progressText' => '[download] 48.5%',
            ];

            $store->write('job-123', $state);

            self::assertSame($state, $store->read('job-123'));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testCancelRequestLifecycleCreatesAndClearsFlagFile(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_cancel_state_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostJobStateStore(new RuntimeBootstrap($root));

            self::assertFalse($store->cancelRequested('job-456'));

            $store->requestCancel('job-456');
            self::assertTrue($store->cancelRequested('job-456'));

            $store->clearCancelRequest('job-456');
            self::assertFalse($store->cancelRequested('job-456'));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }

    public function testReadReturnsNullForInvalidJsonStateFile(): void
    {
        $root = \sys_get_temp_dir() . '/ytd_native_invalid_state_' . \uniqid();
        \mkdir($root, 0777, true);
        putenv('YTD_PROJECT_ROOT=' . $root);

        try {
            $store = new NativeHostJobStateStore(new RuntimeBootstrap($root));
            \mkdir(\dirname($store->statePath('job-bad')), 0777, true);
            \file_put_contents($store->statePath('job-bad'), '{not-json');

            self::assertNull($store->read('job-bad'));
        } finally {
            putenv('YTD_PROJECT_ROOT');
        }
    }
}
