<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class NativeHostResponse
{
    public function __construct(
        public bool $ok,
        public string $code,
        public string $message,
        public ?string $url = null,
        /** @var array<string, mixed> */
        public array $details = [],
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public static function accepted(string $url, array $details = []): self
    {
        return new self(true, 'accepted', 'Download started.', $url, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function success(string $code, string $message, ?string $url = null, array $details = []): self
    {
        return new self(true, $code, $message, $url, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $code, string $message, ?string $url = null, array $details = []): self
    {
        return new self(false, $code, $message, $url, $details);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'ok' => $this->ok,
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->url !== null) {
            $payload['url'] = $this->url;
        }

        return [...$payload, ...$this->details];
    }
}
