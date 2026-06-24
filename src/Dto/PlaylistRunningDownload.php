<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

use Symfony\Component\Process\Process;

final readonly class PlaylistRunningDownload
{
    public function __construct(
        public int $position,
        public SelectedItemMetadata $metadata,
        public Process $process,
    ) {}
}
