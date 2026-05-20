<?php

namespace App\Services;

use App\Models\CheckpointEvent;
use App\Models\PatrolSession;
use App\Models\User;
use Throwable;

/**
 * Domain-specific Web Push payloads for patrol lifecycle events.
 * Sends synchronously; failures are logged and never break API requests.
 */
class PatrolPushNotificationService
{
    public function __construct(
        protected WebPushNotificationService $webPush,
    ) {}

    public function sessionCompleted(PatrolSession $session): void
    {
        try {
            $session->loadMissing(['user', 'zone']);
            $sessionId = (string) $session->id;
            $url = "/admin/patrol-monitoring/{$sessionId}";
            $guardName = $session->user?->name ?? 'Guard';
            $zoneName = $session->zone?->name ?? 'Zone';

            if ($session->status === 'aborted') {
                $this->notifyOperators(
                    'Patrol aborted',
                    "{$guardName} aborted patrol in {$zoneName}.",
                    $url,
                    'patrol-aborted-'.$sessionId
                );

                return;
            }

            $this->notifyOperators(
                'Patrol completed',
                "{$guardName} completed patrol in {$zoneName}.",
                $url,
                'patrol-completed-'.$sessionId
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function checkpointStatusAlert(CheckpointEvent $checkpointEvent, ?string $previousStatus = null): void
    {
        try {
            $status = strtolower((string) $checkpointEvent->status);

            if (! in_array($status, ['suspicious', 'uncertain'], true)) {
                return;
            }

            if ($previousStatus !== null && strtolower($previousStatus) === $status) {
                return;
            }

            $checkpointEvent->loadMissing(['checkpoint', 'patrolSession.user', 'patrolSession.zone']);
            $sessionId = (string) $checkpointEvent->patrol_session_id;
            $checkpointName = $checkpointEvent->checkpoint?->name ?? 'Checkpoint';
            $url = "/admin/patrol-monitoring/{$sessionId}";

            $title = $status === 'suspicious' ? 'Suspicious checkpoint' : 'Uncertain checkpoint';
            $body = "{$checkpointName}: {$status} (session {$sessionId}).";

            $this->notifyOperators($title, $body, $url, "checkpoint-{$status}-{$checkpointEvent->id}");
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $validationResult
     */
    public function validationCompleted(PatrolSession $session, array $validationResult): void
    {
        try {
            $session->loadMissing(['user', 'zone']);
            $sessionId = (string) $session->id;
            $url = "/admin/patrol-monitoring/{$sessionId}";

            $results = collect($validationResult['checkpoint_results'] ?? []);
            $verified = $results->where('status', 'verified')->count();
            $suspicious = $results->where('status', 'suspicious')->count();
            $uncertain = $results->where('status', 'uncertain')->count();
            $rejected = $results->where('status', 'rejected')->count();

            $summary = "Verified {$verified}, suspicious {$suspicious}, uncertain {$uncertain}, rejected {$rejected}.";

            $this->notifyOperators(
                'Patrol validation completed',
                $summary,
                $url,
                'patrol-validation-'.$sessionId,
                ['patrol_session_id' => $sessionId]
            );

            if ($rejected > 0 && $session->user_id) {
                $user = User::query()->find($session->user_id);
                if ($user) {
                    $this->webPush->sendToUser($user, $this->payload(
                        'Checkpoint(s) rejected',
                        "{$rejected} checkpoint(s) were rejected after validation. Review your patrol summary.",
                        '/patrol',
                        'patrol-rejected-'.$sessionId,
                        ['patrol_session_id' => $sessionId]
                    ));
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sendTestToUser(User $user, string $title, string $body): WebPushDeliveryResult
    {
        try {
            return $this->webPush->sendToUser($user, $this->payload(
                $title,
                $body,
                '/patrol',
                'push-test',
                ['test' => true]
            ));
        } catch (Throwable $e) {
            report($e);

            $result = new WebPushDeliveryResult;
            $result->recordFailure($e->getMessage());

            return $result;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function notifyOperators(string $title, string $body, string $url, string $tag, array $data = []): void
    {
        $this->webPush->sendToAdmins($this->payload($title, $body, $url, $tag, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function payload(string $title, string $body, string $url, string $tag, array $data = []): array
    {
        return [
            'title' => $title,
            'body' => $body,
            'icon' => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url' => $url,
            'tag' => $tag,
            'data' => $data,
        ];
    }
}
