<?php

namespace App\Services;

use App\Models\Checkpoint;
use App\Models\CheckpointEvent;
use App\Models\CheckpointEventMetric;
use App\Models\LocationLog;
use App\Models\PatrolSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PatrolValidationService
{
    private const GAP_THRESHOLD_SECONDS = 30;

    private const GAP_FACTOR_MEDIUM_SECONDS = 10;

    private const GAP_FACTOR_LARGE_SECONDS = 60;

    private const DEFAULT_ACCURACY_METERS = 50.0;

    private const MIN_CONTINUOUS_DWELL_SECONDS = 3;

    private const MAX_SPEED_MPS = 41.67;

    private const GPS_JUMP_DISTANCE_METERS = 100.0;

    private const GPS_JUMP_MAX_SECONDS = 5;

    private const EARTH_RADIUS_METERS = 6371000.0;

    private const WEIGHT_DISTANCE = 0.30;

    private const WEIGHT_ACCURACY = 0.25;

    private const WEIGHT_TIME = 0.25;

    private const WEIGHT_STABILITY = 0.20;

    private const RESUME_MAX_CONFIDENCE = 79.0;

    /**
     * Reconstruct patrol movement and validate checkpoint visits for one session.
     *
     * @return array<string, mixed>
     */
    public function validatePatrolSession(PatrolSession $patrolSession): array
    {
        $patrolSession->loadMissing(['zone.checkpoints']);

        $logs = $patrolSession->locationLogs()
            ->orderBy('timestamp')
            ->get();

        $checkpoints = $patrolSession->zone?->checkpoints ?? collect();
        $existingEvents = $patrolSession->checkpointEvents()
            ->get()
            ->keyBy('checkpoint_id');

        $timestampIssues = $this->analyzeTimestampIssues($logs);
        $gaps = $this->detectGaps($logs);
        $segments = $this->buildSegments($logs, $gaps);
        $segmentAnomalies = $this->detectSegmentAnomalies($segments, $logs, $timestampIssues);

        $anomalies = [
            'timestamp_issues' => $timestampIssues,
            'segment_anomalies' => $segmentAnomalies,
            'gaps' => $gaps,
        ];

        $checkpointResults = [];

        foreach ($checkpoints as $checkpoint) {
            $detection = $this->detectCheckpoint($checkpoint, $logs, $segments);
            $scores = $this->calculateScores(
                $checkpoint,
                $detection,
                $gaps,
                $segmentAnomalies[$detection['segment_index'] ?? -1] ?? []
            );

            $confidence = $this->finalConfidence($scores);
            if ($detection['detection_type'] === 'resume') {
                $confidence = min($confidence, self::RESUME_MAX_CONFIDENCE);
            }

            $status = $this->assignStatus(
                $confidence,
                $detection['detection_type'],
                $detection['has_continuous_evidence'],
                $scores['integrity_factor'] < 1.0,
                $detection['detected']
            );

            $event = $this->persistCheckpointEvent(
                $patrolSession,
                $checkpoint,
                $existingEvents->get($checkpoint->id),
                $detection,
                $confidence,
                $status
            );

            $this->persistCheckpointEventMetric($event, $scores);

            $checkpointResults[] = [
                'checkpoint_id' => $checkpoint->id,
                'checkpoint_name' => $checkpoint->name,
                'detection_type' => $detection['detection_type'],
                'confidence_score' => round($confidence, 2),
                'status' => $status,
                'distance_score' => $scores['distance_score'],
                'accuracy_score' => $scores['accuracy_score'],
                'time_score' => $scores['time_score'],
                'stability_score' => $scores['stability_score'],
                'gap_factor' => $scores['gap_factor'],
                'integrity_factor' => $scores['integrity_factor'],
            ];
        }

        return [
            'patrol_session_id' => $patrolSession->id,
            'total_location_logs' => $logs->count(),
            'total_segments' => count($segments),
            'total_gaps' => count($gaps),
            'anomalies' => $anomalies,
            'checkpoint_results' => $checkpointResults,
        ];
    }

    /**
     * @param  Collection<int, LocationLog>  $logs
     * @return list<array{previous_log_id: string|null, next_log_id: string|null, gap_seconds: int}>
     */
    private function detectGaps(Collection $logs): array
    {
        $gaps = [];
        $count = $logs->count();

        for ($i = 1; $i < $count; $i++) {
            $previous = $logs[$i - 1];
            $current = $logs[$i];

            if (! $this->isValidTimestamp($previous->timestamp) || ! $this->isValidTimestamp($current->timestamp)) {
                continue;
            }

            $gapSeconds = (int) round(((int) $current->timestamp - (int) $previous->timestamp) / 1000);

            if ($gapSeconds > self::GAP_THRESHOLD_SECONDS) {
                $gaps[] = [
                    'previous_log_id' => $previous->id,
                    'next_log_id' => $current->id,
                    'gap_seconds' => $gapSeconds,
                ];
            }
        }

        return $gaps;
    }

    /**
     * @param  Collection<int, LocationLog>  $logs
     * @param  list<array{previous_log_id: string|null, next_log_id: string|null, gap_seconds: int}>  $gaps
     * @return list<array{segment_index: int, start_timestamp: int|null, end_timestamp: int|null, log_count: int, duration_seconds: int, log_indices: list<int>}>
     */
    private function buildSegments(Collection $logs, array $gaps): array
    {
        if ($logs->isEmpty()) {
            return [];
        }

        $gapAfterIndex = [];
        foreach ($gaps as $gap) {
            foreach ($logs as $index => $log) {
                if ($log->id === $gap['previous_log_id']) {
                    $gapAfterIndex[$index] = true;
                    break;
                }
            }
        }

        $segments = [];
        $currentIndices = [];
        $segmentIndex = 0;

        foreach ($logs as $index => $log) {
            if ($index > 0 && isset($gapAfterIndex[$index - 1])) {
                if ($currentIndices !== []) {
                    $segments[] = $this->segmentMetadata($segmentIndex, $logs, $currentIndices);
                    $segmentIndex++;
                }
                $currentIndices = [];
            }

            $currentIndices[] = $index;
        }

        if ($currentIndices !== []) {
            $segments[] = $this->segmentMetadata($segmentIndex, $logs, $currentIndices);
        }

        return $segments;
    }

    /**
     * @param  Collection<int, LocationLog>  $logs
     * @param  list<int>  $indices
     * @return array{segment_index: int, start_timestamp: int|null, end_timestamp: int|null, log_count: int, duration_seconds: int, log_indices: list<int>}
     */
    private function segmentMetadata(int $segmentIndex, Collection $logs, array $indices): array
    {
        $timestamps = [];
        foreach ($indices as $index) {
            $ts = $logs[$index]->timestamp;
            if ($this->isValidTimestamp($ts)) {
                $timestamps[] = (int) $ts;
            }
        }

        $start = $timestamps !== [] ? min($timestamps) : null;
        $end = $timestamps !== [] ? max($timestamps) : null;
        $duration = ($start !== null && $end !== null)
            ? (int) max(0, round(($end - $start) / 1000))
            : 0;

        return [
            'segment_index' => $segmentIndex,
            'start_timestamp' => $start,
            'end_timestamp' => $end,
            'log_count' => count($indices),
            'duration_seconds' => $duration,
            'log_indices' => $indices,
        ];
    }

    /**
     * @param  Collection<int, LocationLog>  $logs
     * @return array{duplicate_ids: list<string>, invalid_ids: list<string>, out_of_order_ids: list<string>}
     */
    private function analyzeTimestampIssues(Collection $logs): array
    {
        $duplicateIds = [];
        $invalidIds = [];
        $outOfOrderIds = [];
        $seen = [];

        $previousTs = null;
        foreach ($logs as $log) {
            $ts = $log->timestamp;

            if (! $this->isValidTimestamp($ts)) {
                $invalidIds[] = $log->id;

                continue;
            }

            $tsInt = (int) $ts;

            if (isset($seen[$tsInt])) {
                $duplicateIds[] = $log->id;
            }
            $seen[$tsInt] = true;

            if ($previousTs !== null && $tsInt < $previousTs) {
                $outOfOrderIds[] = $log->id;
            }

            $previousTs = $tsInt;
        }

        return [
            'duplicate_ids' => array_values(array_unique($duplicateIds)),
            'invalid_ids' => array_values(array_unique($invalidIds)),
            'out_of_order_ids' => array_values(array_unique($outOfOrderIds)),
        ];
    }

    /**
     * @param  list<array{segment_index: int, start_timestamp: int|null, end_timestamp: int|null, log_count: int, duration_seconds: int, log_indices: list<int>}>  $segments
     * @param  Collection<int, LocationLog>  $logs
     * @param  array{duplicate_ids: list<string>, invalid_ids: list<string>, out_of_order_ids: list<string>}  $timestampIssues
     * @return array<int, array{major: bool, minor: bool, speed_anomaly: bool, gps_jump: bool, low_accuracy: bool, timestamp_issue: bool}>
     */
    private function detectSegmentAnomalies(array $segments, Collection $logs, array $timestampIssues): array
    {
        $issueLogIds = array_merge(
            $timestampIssues['duplicate_ids'],
            $timestampIssues['invalid_ids'],
            $timestampIssues['out_of_order_ids']
        );
        $issueLogIdSet = array_flip($issueLogIds);

        $results = [];

        foreach ($segments as $segment) {
            $speedAnomaly = false;
            $gpsJump = false;
            $lowAccuracy = false;
            $timestampIssue = false;

            $indices = $segment['log_indices'];

            foreach ($indices as $index) {
                $log = $logs[$index];
                if (isset($issueLogIdSet[$log->id])) {
                    $timestampIssue = true;
                }

                $accuracy = $this->resolveAccuracy($log->accuracy);
                if ($accuracy > 50) {
                    $lowAccuracy = true;
                }
            }

            for ($i = 1; $i < count($indices); $i++) {
                $prev = $logs[$indices[$i - 1]];
                $curr = $logs[$indices[$i]];

                if (! $this->isValidTimestamp($prev->timestamp) || ! $this->isValidTimestamp($curr->timestamp)) {
                    continue;
                }

                $deltaSeconds = max(0.001, ((int) $curr->timestamp - (int) $prev->timestamp) / 1000);
                if ($deltaSeconds > self::GAP_THRESHOLD_SECONDS) {
                    continue;
                }

                $distance = $this->haversineMeters(
                    (float) $prev->latitude,
                    (float) $prev->longitude,
                    (float) $curr->latitude,
                    (float) $curr->longitude
                );

                $calculatedSpeed = $distance / $deltaSeconds;
                $reportedSpeed = $curr->speed !== null ? (float) $curr->speed : null;
                $effectiveSpeed = max($calculatedSpeed, $reportedSpeed ?? 0.0);

                if ($effectiveSpeed > self::MAX_SPEED_MPS) {
                    $speedAnomaly = true;
                }

                if ($distance > self::GPS_JUMP_DISTANCE_METERS && $deltaSeconds <= self::GPS_JUMP_MAX_SECONDS) {
                    $gpsJump = true;
                }
            }

            $major = $speedAnomaly || $gpsJump || $timestampIssue;
            $minor = ! $major && $lowAccuracy;

            $results[$segment['segment_index']] = [
                'major' => $major,
                'minor' => $minor,
                'speed_anomaly' => $speedAnomaly,
                'gps_jump' => $gpsJump,
                'low_accuracy' => $lowAccuracy,
                'timestamp_issue' => $timestampIssue,
            ];
        }

        return $results;
    }

    /**
     * @param  Collection<int, LocationLog>  $logs
     * @param  list<array{segment_index: int, start_timestamp: int|null, end_timestamp: int|null, log_count: int, duration_seconds: int, log_indices: list<int>}>  $segments
     * @return array{
     *     detected: bool,
     *     detection_type: string|null,
     *     has_continuous_evidence: bool,
     *     segment_index: int|null,
     *     entered_at_ms: int|null,
     *     exited_at_ms: int|null,
     *     detected_at_ms: int|null,
     *     closest_distance: float|null,
     *     best_accuracy: float|null,
     *     dwell_seconds: float,
     *     logs_in_radius: list<LocationLog>
     * }
     */
    private function detectCheckpoint(Checkpoint $checkpoint, Collection $logs, array $segments): array
    {
        $empty = [
            'detected' => false,
            'detection_type' => null,
            'has_continuous_evidence' => false,
            'segment_index' => null,
            'entered_at_ms' => null,
            'exited_at_ms' => null,
            'detected_at_ms' => null,
            'closest_distance' => null,
            'best_accuracy' => null,
            'dwell_seconds' => 0.0,
            'logs_in_radius' => [],
        ];

        if ($logs->isEmpty()) {
            return $empty;
        }

        $logsInRadius = [];
        foreach ($logs as $log) {
            $distance = $this->distanceToCheckpoint($checkpoint, $log);
            $effectiveRadius = (float) $checkpoint->radius + ($this->resolveAccuracy($log->accuracy) * 0.5);

            if ($distance <= $effectiveRadius) {
                $logsInRadius[] = $log;
            }
        }

        if ($logsInRadius === []) {
            return $empty;
        }

        $closestDistance = min(array_map(
            fn (LocationLog $log) => $this->distanceToCheckpoint($checkpoint, $log),
            $logsInRadius
        ));

        $bestAccuracy = min(array_map(
            fn (LocationLog $log) => $this->resolveAccuracy($log->accuracy),
            $logsInRadius
        ));

        $logIndexById = [];
        foreach ($logs->values() as $idx => $log) {
            $logIndexById[$log->id] = $idx;
        }

        $continuousCandidate = null;
        foreach ($segments as $segment) {
            $segmentLogs = array_values(array_filter(
                $logsInRadius,
                fn (LocationLog $log) => in_array(
                    $logIndexById[$log->id] ?? -1,
                    $segment['log_indices'],
                    true
                )
            ));

            if ($segmentLogs === []) {
                continue;
            }

            $timestamps = array_values(array_filter(array_map(
                fn (LocationLog $log) => $this->isValidTimestamp($log->timestamp) ? (int) $log->timestamp : null,
                $segmentLogs
            )));

            if ($timestamps === []) {
                continue;
            }

            $dwellSeconds = (max($timestamps) - min($timestamps)) / 1000;

            if ($dwellSeconds >= self::MIN_CONTINUOUS_DWELL_SECONDS) {
                $continuousCandidate = [
                    'segment_index' => $segment['segment_index'],
                    'dwell_seconds' => $dwellSeconds,
                    'entered_at_ms' => min($timestamps),
                    'exited_at_ms' => max($timestamps),
                    'detected_at_ms' => min($timestamps),
                ];
                break;
            }
        }

        if ($continuousCandidate !== null) {
            return array_merge($empty, [
                'detected' => true,
                'detection_type' => 'continuous',
                'has_continuous_evidence' => true,
                'segment_index' => $continuousCandidate['segment_index'],
                'entered_at_ms' => $continuousCandidate['entered_at_ms'],
                'exited_at_ms' => $continuousCandidate['exited_at_ms'],
                'detected_at_ms' => $continuousCandidate['detected_at_ms'],
                'closest_distance' => $closestDistance,
                'best_accuracy' => $bestAccuracy,
                'dwell_seconds' => $continuousCandidate['dwell_seconds'],
                'logs_in_radius' => $logsInRadius,
            ]);
        }

        $resumeLogs = array_values(array_filter(
            $logsInRadius,
            fn (LocationLog $log) => $this->isResumeLog($log)
        ));

        if ($resumeLogs !== []) {
            $timestamps = array_values(array_filter(array_map(
                fn (LocationLog $log) => $this->isValidTimestamp($log->timestamp) ? (int) $log->timestamp : null,
                $resumeLogs
            )));
            $ts = $timestamps !== [] ? min($timestamps) : null;

            return array_merge($empty, [
                'detected' => true,
                'detection_type' => 'resume',
                'has_continuous_evidence' => false,
                'segment_index' => null,
                'entered_at_ms' => $ts,
                'exited_at_ms' => $ts,
                'detected_at_ms' => $ts,
                'closest_distance' => $closestDistance,
                'best_accuracy' => $bestAccuracy,
                'dwell_seconds' => 0.0,
                'logs_in_radius' => $logsInRadius,
            ]);
        }

        return array_merge($empty, [
            'detected' => false,
            'closest_distance' => $closestDistance,
            'best_accuracy' => $bestAccuracy,
            'logs_in_radius' => $logsInRadius,
        ]);
    }

    /**
     * @param  array{
     *     detected: bool,
     *     detection_type: string|null,
     *     has_continuous_evidence: bool,
     *     segment_index: int|null,
     *     closest_distance: float|null,
     *     best_accuracy: float|null,
     *     dwell_seconds: float,
     *     logs_in_radius: list<LocationLog>
     * }  $detection
     * @param  list<array{previous_log_id: string|null, next_log_id: string|null, gap_seconds: int}>  $gaps
     * @param  array{major: bool, minor: bool}  $segmentAnomaly
     * @return array{
     *     distance_score: float,
     *     accuracy_score: float,
     *     time_score: float,
     *     stability_score: float,
     *     gap_factor: float,
     *     integrity_factor: float
     * }
     */
    private function calculateScores(
        Checkpoint $checkpoint,
        array $detection,
        array $gaps,
        array $segmentAnomaly
    ): array {
        if (! $detection['detected']) {
            return [
                'distance_score' => 0.0,
                'accuracy_score' => 0.0,
                'time_score' => 0.0,
                'stability_score' => 0.0,
                'gap_factor' => $this->gapFactorForWindow($gaps, null, null),
                'integrity_factor' => 1.0,
            ];
        }

        $effectiveRadius = (float) $checkpoint->radius + (($detection['best_accuracy'] ?? self::DEFAULT_ACCURACY_METERS) * 0.5);
        $distance = (float) ($detection['closest_distance'] ?? $effectiveRadius);

        $distanceScore = $effectiveRadius > 0
            ? round(max(0.0, min(100.0, 100.0 * (1.0 - ($distance / $effectiveRadius)))), 2)
            : 0.0;

        $accuracy = (float) ($detection['best_accuracy'] ?? self::DEFAULT_ACCURACY_METERS);
        $accuracyScore = $this->accuracyScore($accuracy);

        $dwell = (float) $detection['dwell_seconds'];
        $timeScore = $dwell >= self::MIN_CONTINUOUS_DWELL_SECONDS
            ? 100.0
            : round(max(0.0, min(100.0, ($dwell / self::MIN_CONTINUOUS_DWELL_SECONDS) * 100.0)), 2);

        $stabilityScore = $this->stabilityScore($detection['logs_in_radius']);

        $gapFactor = $this->gapFactorForWindow(
            $gaps,
            $detection['entered_at_ms'] ?? null,
            $detection['exited_at_ms'] ?? null
        );

        $integrityFactor = 1.0;
        if (($segmentAnomaly['major'] ?? false) === true) {
            $integrityFactor = 0.5;
        } elseif (($segmentAnomaly['minor'] ?? false) === true) {
            $integrityFactor = 0.8;
        }

        return [
            'distance_score' => $distanceScore,
            'accuracy_score' => $accuracyScore,
            'time_score' => $timeScore,
            'stability_score' => $stabilityScore,
            'gap_factor' => $gapFactor,
            'integrity_factor' => $integrityFactor,
        ];
    }

    /**
     * @param  array{
     *     distance_score: float,
     *     accuracy_score: float,
     *     time_score: float,
     *     stability_score: float,
     *     gap_factor: float,
     *     integrity_factor: float
     * }  $scores
     */
    private function finalConfidence(array $scores): float
    {
        $base = (self::WEIGHT_DISTANCE * $scores['distance_score'])
            + (self::WEIGHT_ACCURACY * $scores['accuracy_score'])
            + (self::WEIGHT_TIME * $scores['time_score'])
            + (self::WEIGHT_STABILITY * $scores['stability_score']);

        return round($base * $scores['gap_factor'] * $scores['integrity_factor'], 2);
    }

    private function assignStatus(
        float $confidence,
        ?string $detectionType,
        bool $hasContinuousEvidence,
        bool $hasAnomalySignals,
        bool $detected
    ): string {
        if (! $detected || $confidence < 50) {
            return 'rejected';
        }

        if ($confidence >= 80) {
            return 'verified';
        }

        if ($detectionType === 'resume' || ! $hasContinuousEvidence) {
            return 'uncertain';
        }

        if ($hasAnomalySignals) {
            return 'suspicious';
        }

        return 'uncertain';
    }

    /**
     * @param  array{
     *     detected: bool,
     *     detection_type: string|null,
     *     entered_at_ms: int|null,
     *     exited_at_ms: int|null,
     *     detected_at_ms: int|null
     * }  $detection
     */
    private function persistCheckpointEvent(
        PatrolSession $patrolSession,
        Checkpoint $checkpoint,
        ?CheckpointEvent $existing,
        array $detection,
        float $confidence,
        string $status
    ): CheckpointEvent {
        $attributes = [
            'patrol_session_id' => $patrolSession->id,
            'checkpoint_id' => $checkpoint->id,
            'entered_at' => $this->msToDatetime($detection['entered_at_ms'] ?? null),
            'exited_at' => $this->msToDatetime($detection['exited_at_ms'] ?? null),
            'detected_at' => $this->msToDatetime($detection['detected_at_ms'] ?? null),
            'processed_at' => now(),
            'detection_type' => $detection['detection_type'],
            'confidence_score' => $confidence,
            'status' => $status,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return CheckpointEvent::query()->create($attributes);
    }

    /**
     * @param  array{
     *     distance_score: float,
     *     accuracy_score: float,
     *     time_score: float,
     *     stability_score: float,
     *     gap_factor: float,
     *     integrity_factor: float
     * }  $scores
     */
    private function persistCheckpointEventMetric(CheckpointEvent $event, array $scores): void
    {
        CheckpointEventMetric::query()->updateOrCreate(
            ['checkpoint_event_id' => $event->id],
            [
                'distance_score' => $scores['distance_score'],
                'accuracy_score' => $scores['accuracy_score'],
                'time_score' => $scores['time_score'],
                'stability_score' => $scores['stability_score'],
                'gap_factor' => $scores['gap_factor'],
                'integrity_factor' => $scores['integrity_factor'],
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param  list<array{previous_log_id: string|null, next_log_id: string|null, gap_seconds: int}>  $gaps
     */
    private function gapFactorForWindow(array $gaps, ?int $windowStartMs, ?int $windowEndMs): float
    {
        if ($gaps === []) {
            return 1.0;
        }

        $nearestGapSeconds = null;

        foreach ($gaps as $gap) {
            if ($windowStartMs === null || $windowEndMs === null) {
                $nearestGapSeconds = $nearestGapSeconds === null
                    ? $gap['gap_seconds']
                    : min($nearestGapSeconds, $gap['gap_seconds']);

                continue;
            }

            $nearestGapSeconds = $nearestGapSeconds === null
                ? $gap['gap_seconds']
                : min($nearestGapSeconds, $gap['gap_seconds']);
        }

        if ($nearestGapSeconds === null) {
            return 1.0;
        }

        if ($nearestGapSeconds < self::GAP_FACTOR_MEDIUM_SECONDS) {
            return 1.0;
        }

        if ($nearestGapSeconds <= self::GAP_FACTOR_LARGE_SECONDS) {
            return 0.8;
        }

        return 0.5;
    }

    /**
     * @param  list<LocationLog>  $logs
     */
    private function stabilityScore(array $logs): float
    {
        if (count($logs) < 2) {
            return 80.0;
        }

        $score = 100.0;
        $speeds = [];
        $headings = [];

        foreach ($logs as $log) {
            if ($log->speed !== null) {
                $speeds[] = (float) $log->speed;
            }
            if ($log->heading !== null) {
                $headings[] = (float) $log->heading;
            }
        }

        if (count($speeds) >= 2) {
            $variance = $this->variance($speeds);
            if ($variance < 0.01) {
                $score -= 25;
            }
        }

        if (count($headings) >= 3) {
            $headingVariance = $this->variance($headings);
            if ($headingVariance < 1.0) {
                $score -= 20;
            }
        }

        if (count($logs) >= 3) {
            $distances = [];
            for ($i = 1; $i < count($logs); $i++) {
                $distances[] = $this->haversineMeters(
                    (float) $logs[$i - 1]->latitude,
                    (float) $logs[$i - 1]->longitude,
                    (float) $logs[$i]->latitude,
                    (float) $logs[$i]->longitude
                );
            }

            if ($distances !== [] && $this->variance($distances) < 0.5) {
                $score -= 15;
            }
        }

        return round(max(0.0, min(100.0, $score)), 2);
    }

    private function accuracyScore(float $accuracy): float
    {
        if ($accuracy <= 20) {
            return 100.0;
        }

        if ($accuracy > 50) {
            return 0.0;
        }

        return round(100.0 * ((50.0 - $accuracy) / 30.0), 2);
    }

    private function distanceToCheckpoint(Checkpoint $checkpoint, LocationLog $log): float
    {
        return $this->haversineMeters(
            (float) $checkpoint->latitude,
            (float) $checkpoint->longitude,
            (float) $log->latitude,
            (float) $log->longitude
        );
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    private function isResumeLog(LocationLog $log): bool
    {
        $source = strtolower((string) ($log->source ?? ''));

        $trackingState = strtolower((string) ($log->tracking_state ?? ''));

        return $source === 'resume' || $trackingState === 'resumed';
    }

    private function resolveAccuracy(?float $accuracy): float
    {
        return $accuracy === null ? self::DEFAULT_ACCURACY_METERS : (float) $accuracy;
    }

    private function isValidTimestamp(mixed $timestamp): bool
    {
        if ($timestamp === null) {
            return false;
        }

        if (! is_numeric($timestamp)) {
            return false;
        }

        return (int) $timestamp > 0;
    }

    private function msToDatetime(?int $timestampMs): ?Carbon
    {
        if ($timestampMs === null) {
            return null;
        }

        return Carbon::createFromTimestampMs($timestampMs);
    }

    /**
     * @param  list<float>  $values
     */
    private function variance(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sumSq = 0.0;

        foreach ($values as $value) {
            $sumSq += ($value - $mean) ** 2;
        }

        return $sumSq / $count;
    }
}
