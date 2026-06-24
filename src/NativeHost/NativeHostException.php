<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost;

use RuntimeException;

final class NativeHostException extends RuntimeException
{
    public function __construct(
        private readonly string $responseCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getResponseCode(): string
    {
        return $this->responseCode;
    }
}
