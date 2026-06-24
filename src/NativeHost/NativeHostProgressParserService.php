<?php

declare(strict_types=1);

namespace YtdPhp\NativeHost;

final class NativeHostProgressParserService
{
    /**
     * @return array{status:string, progressPercent:?float, progressText:string, outputPath?:string}|null
     */
    public function parse(string $line): ?array
    {
        $normalized = \trim((string) \preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $line));
        if ($normalized === '') {
            return null;
        }

        if (\preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $normalized, $matches) === 1) {
            return [
                'status' => 'downloading',
                'progressPercent' => (float) $matches[1],
                'progressText' => $normalized,
            ];
        }

        if (\preg_match('/(?:📄\s*)?Файл:\s+(.+?)(?:\s+\([^()]*\))?$/u', $normalized, $matches) === 1) {
            return [
                'status' => 'starting',
                'progressPercent' => null,
                'progressText' => $normalized,
                'outputPath' => $matches[1],
            ];
        }

        if (str_contains($normalized, 'Destination:') || str_contains($normalized, 'Начинаю загрузку')) {
            return [
                'status' => 'starting',
                'progressPercent' => null,
                'progressText' => $normalized,
            ];
        }

        return [
            'status' => 'starting',
            'progressPercent' => null,
            'progressText' => $normalized,
        ];
    }
}
