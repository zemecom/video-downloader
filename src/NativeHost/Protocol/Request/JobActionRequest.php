<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Protocol\Request;

use YtdPhp\NativeHost\Protocol\NativeHostRequest;

final readonly class JobActionRequest extends NativeHostRequest
{
    public function __construct(
        public string $action,
        public string $jobId,
    ) {}
}
