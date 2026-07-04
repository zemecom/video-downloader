<?php

declare(strict_types=1);

namespace YtdPhp\Runtime;

final class ProcessEnvironment
{
    /**
     * @return array<string, string>
     */
    public static function build(): array
    {
        return [
            'PATH' => self::buildAugmentedPath(),
        ];
    }

    public static function buildAugmentedPath(): string
    {
        $existingPath = \getenv('PATH');
        $home = \getenv('HOME');

        $segments = \is_string($existingPath) && $existingPath !== ''
            ? \explode(PATH_SEPARATOR, $existingPath)
            : [];

        $preferred = \array_filter([
            \is_string($home) && $home !== '' ? $home . '/.local/bin' : null,
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ]);

        $ordered = [];
        foreach (\array_merge($preferred, $segments) as $segment) {
            if (!\is_string($segment)) {
                continue;
            }
            if ($segment === '') {
                continue;
            }
            if (isset($ordered[$segment])) {
                continue;
            }
            $ordered[$segment] = true;
        }

        return \implode(PATH_SEPARATOR, array_keys($ordered));
    }
}
