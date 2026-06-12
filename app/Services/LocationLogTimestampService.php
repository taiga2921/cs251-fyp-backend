<?php

namespace App\Services;

use App\Models\LocationLog;

class LocationLogTimestampService
{
    /**
     * Ensure device timestamps are strictly increasing per patrol session.
     *
     * Mobile browsers often emit duplicate second-precision GPS timestamps; movement
     * validation requires unique, ordered millisecond values within a segment.
     */
    public function normalizeForPatrolSession(string $patrolSessionId, int $timestamp): int
    {
        if ($timestamp <= 0) {
            $timestamp = (int) now()->getTimestampMs();
        }

        $maxExisting = LocationLog::query()
            ->where('patrol_session_id', $patrolSessionId)
            ->max('timestamp');

        if ($maxExisting === null) {
            return $timestamp;
        }

        $maxInt = (int) $maxExisting;

        return $timestamp <= $maxInt ? $maxInt + 1 : $timestamp;
    }
}
