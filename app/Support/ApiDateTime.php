<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

class ApiDateTime
{
    /**
     * Serialize a datetime for JSON API responses as ISO-8601 UTC.
     */
    public static function format(DateTimeInterface|CarbonInterface|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof CarbonInterface ? $value : Carbon::parse($value))
            ->utc()
            ->toIso8601String();
    }
}
