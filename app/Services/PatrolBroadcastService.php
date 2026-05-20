<?php

namespace App\Services;

use App\Events\Patrol\PatrolCheckpointSuspicious;
use App\Events\Patrol\PatrolCheckpointVerified;
use App\Events\Patrol\PatrolRouteUpdated;
use App\Events\Patrol\PatrolSessionCompleted;
use App\Events\Patrol\PatrolSessionStarted;
use App\Events\Patrol\PatrolValidationCompleted;
use App\Http\Resources\CheckpointEventResource;
use App\Http\Resources\PatrolSessionResource;
use App\Models\CheckpointEvent;
use App\Models\PatrolRoute;
use App\Models\PatrolSession;

class PatrolBroadcastService
{
    public function __construct(
        protected PatrolPushNotificationService $pushNotifications,
    ) {}

    public function sessionStarted(PatrolSession $session): void
    {
        if (! $this->shouldBroadcast()) {
            return;
        }

        $session->loadMissing(['user', 'zone', 'blockchainRecord']);

        PatrolSessionStarted::dispatch(
            (string) $session->id,
            (new PatrolSessionResource($session))->resolve(),
        );
    }

    public function sessionCompleted(PatrolSession $session): void
    {
        if ($this->shouldBroadcast()) {
            $session->loadMissing(['user', 'zone', 'blockchainRecord']);

            PatrolSessionCompleted::dispatch(
                (string) $session->id,
                (string) $session->status,
                (new PatrolSessionResource($session))->resolve(),
            );
        }

        $this->pushNotifications->sessionCompleted($session);
    }

    public function routeUpdated(PatrolRoute $route): void
    {
        if (! $this->shouldBroadcast()) {
            return;
        }

        PatrolRouteUpdated::dispatch(
            (string) $route->patrol_session_id,
            (float) $route->latitude,
            (float) $route->longitude,
            $route->accuracy !== null ? (float) $route->accuracy : null,
            $route->recorded_at?->toIso8601String() ?? now()->toIso8601String(),
            (string) $route->id,
        );
    }

    public function checkpointUpdated(CheckpointEvent $checkpointEvent, ?string $previousStatus = null): void
    {
        $checkpointEvent->loadMissing(['checkpoint', 'patrolSession']);
        $payload = $this->checkpointPayload($checkpointEvent);
        $status = strtolower((string) $checkpointEvent->status);

        if ($this->shouldBroadcast()) {
            if ($status === 'verified') {
                PatrolCheckpointVerified::dispatch((string) $checkpointEvent->patrol_session_id, $payload);
            } elseif (in_array($status, ['suspicious', 'uncertain'], true)) {
                PatrolCheckpointSuspicious::dispatch((string) $checkpointEvent->patrol_session_id, $payload);
            }
        }

        $this->pushNotifications->checkpointStatusAlert($checkpointEvent, $previousStatus);
    }

    /**
     * @param  array<string, mixed>  $validationResult
     */
    public function validationCompleted(PatrolSession $session, array $validationResult): void
    {
        if ($this->shouldBroadcast()) {
            PatrolValidationCompleted::dispatch(
                (string) $session->id,
                $validationResult,
            );
        }

        $this->pushNotifications->validationCompleted($session, $validationResult);
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkpointPayload(CheckpointEvent $checkpointEvent): array
    {
        $resolved = (new CheckpointEventResource($checkpointEvent))->resolve();

        return [
            'patrol_session_id' => $checkpointEvent->patrol_session_id,
            'checkpoint_event_id' => $checkpointEvent->id,
            'checkpoint_id' => $checkpointEvent->checkpoint_id,
            'status' => $checkpointEvent->status,
            'confidence_score' => $checkpointEvent->confidence_score,
            'detected_at' => $checkpointEvent->detected_at,
            'checkpoint' => $resolved['checkpoint'] ?? null,
            'event' => $resolved,
        ];
    }

    protected function shouldBroadcast(): bool
    {
        $driver = config('broadcasting.default');

        return is_string($driver) && ! in_array($driver, ['null', 'log'], true);
    }
}
