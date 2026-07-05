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
        ['format_id' => '251'],
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
foreach ($args as $index => $arg) {
    if ($arg === '-o' && isset($args[$index + 1])) {
        $outputPath = $args[$index + 1];
        break;
    }
}

$resolveOutputPath = static function (?string $path) use ($metadata): string {
    $resolved = (string) $path;

    return str_replace(
        ['%(title)s', '%(id)s', '%(ext)s'],
        [
            (string) ($metadata['title'] ?? ''),
            (string) ($metadata['id'] ?? ''),
            (string) ($metadata['ext'] ?? 'mp4'),
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

if (!in_array('--continue', $args, true)) {
    fwrite(STDERR, "missing continue flag\n");

    exit(1);
}

$attemptFile = $root . '/download-attempts.txt';
$attempt = is_file($attemptFile) ? ((int) trim((string) file_get_contents($attemptFile)) + 1) : 1;
file_put_contents($attemptFile, (string) $attempt);
file_put_contents($root . '/last-download-args-' . $attempt . '.json', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$resolvedPath = $resolveOutputPath($outputPath);
$directory = dirname($resolvedPath);
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}

if ($attempt === 1) {
    file_put_contents($resolvedPath . '.part', 'partial-video');
    fwrite(STDOUT, "[download]   15.0% of 10.00MiB at 2.00MiB/s ETA 00:05\n");
    fwrite(STDERR, "ERROR: unable to download video data: HTTP Error 403: Forbidden\n");

    exit(1);
}

if (is_file($resolvedPath . '.part')) {
    file_put_contents($root . '/download-resume-seen.txt', '1');
    unlink($resolvedPath . '.part');
}

file_put_contents($resolvedPath, 'video-bytes');
fwrite(STDOUT, "[download] 100% of 10.00MiB in 00:00:02 at 5.00MiB/s\n");

exit(0);
