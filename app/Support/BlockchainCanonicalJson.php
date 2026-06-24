<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;

class BlockchainCanonicalJson
{
    /**
     * @param  array<string|int, mixed>  $payload
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public static function encode(array $payload): string
    {
        $normalized = self::normalize($payload);

        return json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return array<string|int, mixed>|list<mixed>|string|int|float|bool|null
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return self::formatUtcTimestamp($value);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => self::normalize($item), $value);
            }

            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[(string) $key] = self::normalize($item);
            }

            ksort($normalized, SORT_STRING);

            return $normalized;
        }

        throw new InvalidArgumentException(
            'Unsupported value type for canonical JSON encoding: '.gettype($value)
        );
    }

    private static function formatUtcTimestamp(DateTimeInterface $value): string
    {
        $carbon = $value instanceof CarbonInterface
            ? $value->copy()
            : Carbon::parse($value);

        return $carbon->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
