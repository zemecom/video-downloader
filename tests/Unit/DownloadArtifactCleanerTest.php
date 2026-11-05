<?php

declare(strict_types=1);

namespace YtdPhp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YtdPhp\Download\Process\DownloadArtifactCleaner;

final class DownloadArtifactCleanerTest extends TestCase
{
    public function testCleanupReturnsZeroForNullOrEmptyTarget(): void
    {
        $cleaner = new DownloadArtifactCleaner();

        self::assertSame(0, $cleaner->cleanupFailedDownloadArtifacts(null));
        self::assertSame(0, $cleaner->cleanupFailedDownloadArtifacts(''));
    }

    public function testCleanupRemovesDirectArtifactsAndPartialFragments(): void
    {
        $dir = \sys_get_temp_dir() . '/ytd_artifact_cleaner_test_' . \uniqid();
        \mkdir($dir, 0777, true);

        try {
            $base = $dir . '/Awesome_Video_2160p.mkv';

            $targetPart = $base . '.part';
            $targetYtdl = $base . '.ytdl';
            $videoPart = $dir . '/Awesome_Video_2160p.f401.mp4.part';
            $audioPart = $dir . '/Awesome_Video_2160p.f140.m4a.part';
            $tempFrag = $dir . '/Awesome_Video_2160p.part-Frag1.part';
            $unrelatedPart = $dir . '/Other_Video.f137.mp4.part';
            $completeTarget = $base;
            $unrelatedFile = $dir . '/Awesome_Video_2160p.description';

            \file_put_contents($targetPart, 'dummy');
            \file_put_contents($targetYtdl, 'dummy');
            \file_put_contents($videoPart, 'dummy');
            \file_put_contents($audioPart, 'dummy');
            \file_put_contents($tempFrag, 'dummy');
            \file_put_contents($unrelatedPart, 'keep-me');
            \file_put_contents($completeTarget, 'dummy-complete');
            \file_put_contents($unrelatedFile, 'keep-me-too');

            $cleaner = new DownloadArtifactCleaner();
            $removedCount = $cleaner->cleanupFailedDownloadArtifacts($base);

            // Should have removed: completeTarget, targetPart, targetYtdl, videoPart, audioPart, tempFrag (6 files)
            self::assertSame(6, $removedCount);
            self::assertFileDoesNotExist($targetPart);
            self::assertFileDoesNotExist($targetYtdl);
            self::assertFileDoesNotExist($videoPart);
            self::assertFileDoesNotExist($audioPart);
            self::assertFileDoesNotExist($tempFrag);
            self::assertFileDoesNotExist($completeTarget);

            // Unrelated files must remain
            self::assertFileExists($unrelatedPart);
            self::assertFileExists($unrelatedFile);
        } finally {
            $files = @\scandir($dir);
            if (\is_array($files)) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        @\unlink($dir . '/' . $file);
                    }
                }
            }
            @\rmdir($dir);
        }
    }
}
