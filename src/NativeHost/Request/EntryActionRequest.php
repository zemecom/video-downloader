<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost\Request;

use YtdPhp\NativeHost\NativeHostRequest;

final readonly class EntryActionRequest extends NativeHostRequest
{
    public function __construct(
        public string $action,
        public string $entryId,
    ) {}
}
