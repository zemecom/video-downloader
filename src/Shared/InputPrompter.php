<?php

declare(strict_types=1);

namespace YtdPhp\Shared;

final class InputPrompter
{
    /** @var null|callable(string): string */
    private $reader;

    public function setReader(?callable $reader): void
    {
        $this->reader = $reader;
    }

    public function ask(string $prompt): string
    {
        if (\is_callable($this->reader)) {
            return (string) ($this->reader)($prompt);
        }

        \fwrite(STDOUT, $prompt);
        $line = \fgets(STDIN);

        return $line === false ? '' : \trim($line);
    }
}
