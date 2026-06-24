<?php

declare(strict_types=1);

namespace YtdPhp\Playlist;

use Symfony\Component\Process\Process;

final readonly class PlaylistRunningDownload
{
    public function __construct(
        public int $position,
        public SelectedItemMetadata $metadata,
        public Process $process,
    ) {}
}
