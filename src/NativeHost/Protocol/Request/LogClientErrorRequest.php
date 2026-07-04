<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Protocol\Request;

use YtdPhp\NativeHost\Protocol\NativeHostRequest;

final readonly class LogClientErrorRequest extends NativeHostRequest
{
    public function __construct(
        public string $action,
        public string $errorMessage,
        public ?string $errorStack,
    ) {}
}
