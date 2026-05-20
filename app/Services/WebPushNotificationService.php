<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use JsonException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushNotificationService
{
    private ?WebPush $client = null;

    public function isConfigured(): bool
    {
        $public = config('webpush.vapid.public_key');
        $private = config('webpush.vapid.private_key');

        return is_string($public) && $public !== ''
            && is_string($private) && $private !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendToUser(User $user, array $payload): WebPushDeliveryResult
    {
        $result = new WebPushDeliveryResult;

        if (! $this->isConfigured()) {
            return $result;
        }

        $normalized = $this->resolvePayload($payload, $result);
        if ($normalized === null) {
            return $result;
        }

        PushSubscription::query()
            ->where('user_id', $user->id)
            ->each(function (PushSubscription $subscription) use ($normalized, $result): void {
                try {
                    $this->sendToSubscription($subscription, $normalized, $result);
                } catch (Throwable $e) {
                    $result->recordFailure($e->getMessage());

                    Log::warning('[webpush] subscription dispatch failed', [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'message' => $e->getMessage(),
                    ]);
                }
            });

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendToAdmins(array $payload): WebPushDeliveryResult
    {
        return $this->sendToRole('Admin', $payload)
            ->merge($this->sendToRole('Security Operator', $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendToRole(string $roleName, array $payload): WebPushDeliveryResult
    {
        $result = new WebPushDeliveryResult;

        if (! $this->isConfigured()) {
            return $result;
        }

        $normalized = $this->resolvePayload($payload, $result);
        if ($normalized === null) {
            return $result;
        }

        User::query()
            ->whereHas('role', fn ($query) => $query->where('name', $roleName))
            ->select('users.*')
            ->chunkById(50, function ($users) use ($normalized, $result): void {
                foreach ($users as $user) {
                    try {
                        $result->merge($this->sendToUser($user, $normalized));
                    } catch (Throwable $e) {
                        $result->recordFailure($e->getMessage());

                        Log::warning('[webpush] role dispatch failed', [
                            'user_id' => $user->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendToSubscription(
        PushSubscription $subscription,
        array $payload,
        ?WebPushDeliveryResult $result = null,
    ): bool {
        if (! $this->isConfigured()) {
            return false;
        }

        $aggregate = $result ?? new WebPushDeliveryResult;

        $normalized = $this->resolvePayload($payload, $aggregate);
        if ($normalized === null) {
            return false;
        }

        try {
            $keys = $subscription->keys ?? [];
            $p256dh = $keys['p256dh'] ?? null;
            $auth = $keys['auth'] ?? null;

            if (! is_string($subscription->endpoint) || $subscription->endpoint === ''
                || ! is_string($p256dh) || $p256dh === ''
                || ! is_string($auth) || $auth === '') {
                $aggregate->recordInvalidSubscription();

                Log::warning('[webpush] invalid subscription row', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'endpoint' => is_string($subscription->endpoint) ? $subscription->endpoint : null,
                ]);

                return false;
            }

            $webSubscription = Subscription::create([
                'endpoint' => $subscription->endpoint,
                'keys' => [
                    'p256dh' => $p256dh,
                    'auth' => $auth,
                ],
            ]);

            $report = $this->client()->sendOneNotification(
                $webSubscription,
                json_encode($normalized, JSON_THROW_ON_ERROR)
            );

            $statusCode = $report->getResponse()?->getStatusCode();

            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
                $aggregate->recordExpired();

                Log::info('[webpush] subscription expired', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'status_code' => $statusCode,
                    'endpoint' => $subscription->endpoint,
                ]);

                return false;
            }

            if ($report->isSuccess()) {
                $subscription->forceFill(['last_used_at' => now()])->save();
                $aggregate->recordSuccess();

                Log::info('[webpush] delivery succeeded', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'status_code' => $statusCode,
                ]);

                return true;
            }

            $reason = $report->getReason();
            $aggregate->recordFailure($reason);

            Log::warning('[webpush] delivery failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'endpoint' => $subscription->endpoint,
                'status_code' => $statusCode,
                'reason' => $reason,
            ]);

            return false;
        } catch (Throwable $e) {
            $aggregate->recordFailure($e->getMessage());

            Log::warning('[webpush] send failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function resolvePayload(array $payload, WebPushDeliveryResult $result): ?array
    {
        if ($payload === []) {
            $result->recordInvalidPayload();

            Log::warning('[webpush] payload validation failed', [
                'reason' => 'Payload must be a non-empty array.',
            ]);

            return null;
        }

        try {
            $normalized = $this->normalizePayload($payload);
            json_encode($normalized, JSON_THROW_ON_ERROR);

            return $normalized;
        } catch (JsonException $e) {
            $result->recordInvalidPayload();

            Log::warning('[webpush] payload validation failed', [
                'reason' => 'Payload could not be encoded as JSON.',
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            $result->recordInvalidPayload();

            Log::warning('[webpush] payload validation failed', [
                'reason' => 'Payload normalization failed.',
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $payload): array
    {
        $url = $payload['url'] ?? '/';
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'title' => (string) ($payload['title'] ?? 'Surveillance Patrol'),
            'body' => (string) ($payload['body'] ?? ''),
            'icon' => $payload['icon'] ?? '/icons/icon-192.png',
            'badge' => $payload['badge'] ?? '/icons/icon-192.png',
            'url' => $url,
            'tag' => $payload['tag'] ?? 'patrol-alert',
            'data' => array_merge(['url' => $url], $data),
        ];
    }

    protected function client(): WebPush
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ]);

        return $this->client;
    }
}
