<?php

declare(strict_types=1);

namespace YtdPhp\Playlist;

use YtdPhp\Playlist\PlaylistInfo;
use YtdPhp\Playlist\PlaylistItem;

final readonly class PlaylistPayloadMapper
{
    /**
     * @return array<mixed>|null
     */
    public function decode(string $output): ?array
    {
        $payload = \json_decode(\trim($output), true);
        if (\is_array($payload)) {
            if (array_is_list($payload) && \count($payload) === 1 && \is_array($payload[0])) {
                return $payload[0];
            }

            return $payload;
        }

        return null;
    }

    /**
     * @param array<mixed> $payload
     */
    public function mapPlaylistInfo(string $videoUrl, array $payload): PlaylistInfo
    {
        $playlistId = \trim((string) ($payload['id'] ?? ''));
        $playlistTitle = \trim((string) ($payload['title'] ?? $payload['playlist_title'] ?? $playlistId ?: 'playlist'));
        $entries = $payload['entries'] ?? [];
        if (!\is_array($entries)) {
            $entries = [];
        }

        $items = [];
        foreach ($entries as $index => $entry) {
            $items[] = $this->normalizePlaylistEntry(\is_array($entry) ? $entry : null, $index + 1);
        }

        $totalCount = $this->coerceInt($payload['playlist_count'] ?? null) ?? \count($items);
        if ($totalCount === 0 && $items !== []) {
            $totalCount = \count($items);
        }

        return new PlaylistInfo(
            $playlistId !== '' ? $playlistId : 'playlist',
            $playlistTitle,
            $videoUrl,
            $items,
            $totalCount,
        );
    }

    public function coerceInt(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizePlaylistEntry(?array $entry, int $index): PlaylistItem
    {
        [$status, $selectable] = $this->detectItemStatus($entry);
        $title = '';
        $url = '';
        if (\is_array($entry)) {
            $title = \trim((string) ($entry['title'] ?? $entry['fulltitle'] ?? $entry['id'] ?? ''));
            $url = \trim((string) ($entry['webpage_url'] ?? $entry['url'] ?? ''));
        }
        if ($title === '') {
            $title = 'Видео ' . $index;
        }

        return new PlaylistItem($index, $title, $url, $status, $selectable);
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function detectItemStatus(?array $entry): array
    {
        if (!\is_array($entry)) {
            return ['unavailable', false];
        }

        $availability = \strtolower(\trim((string) ($entry['availability'] ?? '')));
        $title = \strtolower(\trim((string) ($entry['title'] ?? '')));
        if (\str_contains($availability, 'private') || \str_contains($title, 'private')) {
            return ['private', false];
        }
        if (\str_contains($availability, 'deleted') || \str_contains($title, 'deleted')) {
            return ['deleted', false];
        }
        if (\str_contains($availability, 'unavailable') || \str_contains($title, 'not available')) {
            return ['unavailable', false];
        }
        if ($availability !== '') {
            return [$availability, true];
        }

        return ['available', true];
    }
}
