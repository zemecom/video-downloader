#!/usr/bin/env php
<?php

$args = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
array_shift($args);
$root = dirname(__DIR__);
file_put_contents($root . '/last-ffmpeg-args.json', json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "merge failed\n");

exit(1);
