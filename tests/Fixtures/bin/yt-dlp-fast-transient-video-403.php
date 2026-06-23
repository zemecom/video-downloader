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
        ['format_id' => '140', 'ext' => 'm4a', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2', 'abr' => 128],
    ],
];

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

if (!is_string($outputPath) || $outputPath === '' || !is_string($formatId) || $formatId === '') {
    fwrite(STDERR, "missing output path or format\n");

    exit(1);
}

if (!in_array('--continue', $args, true)) {
    fwrite(STDERR, "missing continue flag\n");

    exit(1);
}

$attemptFile = $root . '/stream-' . $formatId . '-attempts.txt';
$attempt = is_file($attemptFile) ? ((int) trim((string) file_get_contents($attemptFile)) + 1) : 1;
file_put_contents($attemptFile, (string) $attempt);
file_put_contents($root . '/stream-' . $formatId . '-args-' . $attempt . '.json', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$resolvedPath = $resolveOutputPath($outputPath);
$directory = dirname($resolvedPath);
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}

if ($formatId === '137' && $attempt === 1) {
    file_put_contents($resolvedPath . '.part', 'partial-video');
    fwrite(STDOUT, "[download]    2.0% of 408.80MiB at 11.07MiB/s ETA 00:36\n");
    fwrite(STDERR, "ERROR: unable to download video data: HTTP Error 403: Forbidden\n");

    exit(1);
}

if ($formatId === '137' && is_file($resolvedPath . '.part')) {
    file_put_contents($root . '/video-resume-seen.txt', '1');
    unlink($resolvedPath . '.part');
}

file_put_contents($resolvedPath, 'stream-' . $formatId);
fwrite(STDOUT, "[download] 100% of " . $formatId . PHP_EOL);

exit(0);
