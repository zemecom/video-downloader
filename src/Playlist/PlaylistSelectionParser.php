<?php

declare(strict_types=1);

namespace YtdPhp\Playlist;

use InvalidArgumentException;
use YtdPhp\Playlist\PlaylistItem;

final readonly class PlaylistSelectionParser
{
    /**
     * @param list<PlaylistItem> $items
     * @return list<int>
     */
    public function parse(string $rawValue, array $items): array
    {
        $cleaned = \strtolower(\trim($rawValue));
        $selectableIndexes = [];
        $maxIndex = 0;
        foreach ($items as $item) {
            $maxIndex = \max($maxIndex, $item->playlistIndex);
            if ($item->selectable) {
                $selectableIndexes[] = $item->playlistIndex;
            }
        }

        if ($cleaned === '') {
            throw new InvalidArgumentException('Пустой выбор.');
        }
        if ($cleaned === 'all') {
            sort($selectableIndexes);

            return $selectableIndexes;
        }

        $indexes = [];
        $tokens = \array_filter(\array_map('trim', explode(',', $cleaned)), static fn(string $value): bool => $value !== '');
        if ($tokens === []) {
            throw new InvalidArgumentException('Пустой выбор.');
        }

        foreach ($tokens as $token) {
            if (\str_contains($token, '-')) {
                $this->parseRangeToken($token, $maxIndex, $selectableIndexes, $indexes);
                continue;
            }

            if (!ctype_digit($token)) {
                throw new InvalidArgumentException('Непонятный выбор: ' . $token);
            }
            $number = (int) $token;
            $this->validateSelectedNumber($number, $maxIndex, $selectableIndexes);
            $indexes[$number] = true;
        }

        if ($indexes === []) {
            throw new InvalidArgumentException('Не выбрано ни одного ролика.');
        }

        $result = \array_map('intval', array_keys($indexes));
        sort($result);

        return $result;
    }

    /**
     * @param list<int> $selectableIndexes
     * @param array<int, true> $indexes
     */
    private function parseRangeToken(string $token, int $maxIndex, array $selectableIndexes, array &$indexes): void
    {
        $parts = explode('-', $token);
        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Поддерживаются только диапазоны вида 5-8.');
        }
        $start = (int) $parts[0];
        $end = (int) $parts[1];
        if ($start > $end) {
            throw new InvalidArgumentException('Начало диапазона не может быть больше конца.');
        }
        for ($number = $start; $number <= $end; ++$number) {
            $this->validateSelectedNumber($number, $maxIndex, $selectableIndexes);
            $indexes[$number] = true;
        }
    }

    /**
     * @param list<int> $selectableIndexes
     */
    private function validateSelectedNumber(int $number, int $maxIndex, array $selectableIndexes): void
    {
        if ($number < 1 || $number > $maxIndex) {
            throw new InvalidArgumentException('Номер ' . $number . ' вне диапазона.');
        }
        if (!\in_array($number, $selectableIndexes, true)) {
            throw new InvalidArgumentException('Ролик ' . $number . ' нельзя выбрать.');
        }
    }
}
