<?php

declare(strict_types=1);

namespace YtdPhp\Download\Format;

final readonly class FastStreamFormatPair
{
    public function __construct(
        public FastStreamFormat $video,
        public FastStreamFormat $audio,
    ) {}
}
