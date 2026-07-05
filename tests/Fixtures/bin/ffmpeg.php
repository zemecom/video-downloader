#!/usr/bin/env php
<?php

$args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
array_shift($args);
$root = dirname(__DIR__);
file_put_contents($root . '/last-ffmpeg-args.json', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$outputPath = $args[array_key_last($args)] ?? null;
if (!is_string($outputPath) || $outputPath === '') {
    fwrite(STDERR, "missing output path\n");

    exit(1);
}

$directory = dirname($outputPath);
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
file_put_contents($outputPath, 'merged-bytes');

exit(0);
