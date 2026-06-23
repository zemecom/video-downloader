<?php

declare(strict_types=1);

namespace YtdPhp\Dto;

final readonly class FastStreamFormatPair
{
    public function __construct(
        public FastStreamFormat $video,
        public FastStreamFormat $audio,
    ) {}
}
