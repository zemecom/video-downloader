#!/usr/bin/env php
<?php

$args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
array_shift($args);
$root = dirname(__DIR__);

$metadata = [
    'title' => 'My Cool Video',
    'id' => 'abc 123',
    'ext' => 'webm',
    'requested_formats' => [
        ['format_id' => '137'],
        ['format_id' => '140'],
    ],
    'formats' => [
        ['format_id' => '137', 'ext' => 'mp4', 'vcodec' => 'avc1.640028', 'acodec' => 'none', 'height' => 1080, 'fps' => 30, 'tbr' => 4500],
        ['format_id' => '251', 'ext' => 'webm', 'vcodec' => 'none', 'acodec' => 'opus', 'abr' => 160],
        ['format_id' => '140', 'ext' => 'm4a', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2', 'abr' => 128],
    ],
];

foreach ($args as $index => $arg) {
    if ($arg === '--load-info-json' && isset($args[$index + 1]) && is_file($args[$index + 1])) {
        file_put_contents($root . '/last-info-json.txt', $args[$index + 1]);
        $loaded = json_decode((string) file_get_contents($args[$index + 1]), true);
        if (is_array($loaded)) {
            $metadata = array_replace($metadata, $loaded);
        }
    }
}

$outputPath = null;
$formatId = null;
foreach ($args as $index => $arg) {
    if ($arg === '-o' && isset($args[$index + 1])) {
        $outputPath = $args[$index + 1];
    }
    if ($arg === '-f' && isset($args[$index + 1])) {
        $formatId = $args[$index + 1];
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

if (!is_string($outputPath) || $outputPath === '' || !is_string($formatId) || $formatId === '') {
    fwrite(STDERR, "missing output path or format\n");

    exit(1);
}

file_put_contents($root . '/stream-' . $formatId . '-args.json', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$resolvedPath = $resolveOutputPath($outputPath);
$directory = dirname($resolvedPath);
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}
file_put_contents($resolvedPath, 'stream-' . $formatId);
fwrite(STDOUT, "[download] 100% of " . $formatId . PHP_EOL);

exit(0);
