#!/usr/bin/env php
<?php

$args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
array_shift($args);

$metadata = [
    'title' => 'My Cool Video',
    'id' => 'abc 123',
    'ext' => 'webm',
    'requested_formats' => [
        ['format_id' => '137'],
        ['format_id' => '251'],
    ],
];

foreach ($args as $index => $arg) {
    if ($arg === '--load-info-json' && isset($args[$index + 1]) && is_file($args[$index + 1])) {
        file_put_contents(dirname(__DIR__) . '/last-info-json.txt', $args[$index + 1]);
        $loaded = json_decode((string) file_get_contents($args[$index + 1]), true);
        if (is_array($loaded)) {
            $metadata = array_replace($metadata, $loaded);
        }
    }
}

$outputPath = null;
foreach ($args as $index => $arg) {
    if ($arg === '-o' && isset($args[$index + 1])) {
        $outputPath = $args[$index + 1];
        break;
    }
}

$resolveOutputPath = static function (?string $path) use ($metadata): string {
    $resolved = (string) $path;

    return str_replace(
        ['%(title)s', '%(id)s', '%(ext)s', '%(resolution)s'],
        [
            (string) ($metadata['title'] ?? ''),
            (string) ($metadata['id'] ?? ''),
            (string) ($metadata['ext'] ?? 'mp4'),
            (string) ($metadata['resolution'] ?? '1080p'),
        ],
        $resolved,
    );
};

if (in_array('--dump-json', $args, true)) {
    fwrite(STDOUT, json_encode($metadata, JSON_UNESCAPED_UNICODE) . PHP_EOL);

    exit(0);
}

if (in_array('--get-filename', $args, true)) {
    fwrite(STDOUT, $resolveOutputPath($outputPath) . PHP_EOL);

    exit(0);
}

if (!is_string($outputPath) || $outputPath === '') {
    fwrite(STDERR, "missing output path\n");

    exit(1);
}

file_put_contents(dirname(__DIR__) . '/last-download-args.json', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$resolvedPath = $resolveOutputPath($outputPath);
$directory = dirname($resolvedPath);
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
fwrite(STDOUT, "[download]   50.0% of 10.00MiB at 2.00MiB/s ETA 00:05\n");
file_put_contents($resolvedPath, 'video-bytes');

exit(0);
