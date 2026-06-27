<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompletePasswordSetupRequest;
use App\Http\Requests\StartTwoFactorSetupRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Requests\VerifyTwoFactorSetupRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthAuditService;
use App\Services\Auth\AuthLoginChallengeService;
use App\Services\Auth\InvalidOtpChallengeException;
use App\Services\Auth\InvalidPasswordSetupTokenException;
use App\Services\Auth\InvalidRefreshTokenException;
use App\Services\Auth\InvalidTwoFactorSetupTokenException;
use App\Services\Auth\LoginRateLimitedException;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\PasswordSetupService;
use App\Services\Auth\RefreshTokenReuseException;
use App\Services\Auth\RefreshTokenService;
use App\Services\Auth\TwoFactorSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly PasswordSetupService $passwordSetupService,
        private readonly TwoFactorSetupService $twoFactorSetupService,
        private readonly AuthLoginChallengeService $authLoginChallengeService,
        private readonly LoginRateLimiter $loginRateLimiter,
        private readonly AuthAuditService $authAuditService,
    ) {}

    /**
     * Validate credentials and route to password setup, 2FA setup, or OTP challenge.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'data' => ['errors' => $validator->errors()->toArray()],
                ], 422);
            }

            $credentials = $validator->validated();
            $email = $this->loginRateLimiter->normalizeEmail($credentials['email']);
            $credentials['email'] = $email;

            try {
                $this->loginRateLimiter->ensureNotLocked($email, $request);
            } catch (LoginRateLimitedException $exception) {
                $this->authAuditService->record(
                    AuthAuditService::EVENT_LOGIN_RATE_LIMITED,
                    AuthAuditService::STATUS_BLOCKED,
                    $request,
                    email: $email,
                );

                return $this->loginLockoutResponse($exception->retryAfterSeconds);
            }

            $user = User::query()->where('email', $email)->first();

            if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
                $justLocked = $this->loginRateLimiter->recordFailedAttempt($email, $request);
                $this->authAuditService->record(
                    AuthAuditService::EVENT_LOGIN_PASSWORD_FAILURE,
                    AuthAuditService::STATUS_FAILURE,
                    $request,
                    user: $user,
                    email: $email,
                );

                if ($justLocked) {
                    $this->authAuditService->record(
                        AuthAuditService::EVENT_LOGIN_RATE_LIMITED,
                        AuthAuditService::STATUS_BLOCKED,
                        $request,
                        user: $user,
                        email: $email,
                    );

                    return $this->loginLockoutResponse(
                        $this->loginRateLimiter->availableIn($email, $request)
                    );
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials.',
                    'data' => null,
                ], 401);
            }

            $this->loginRateLimiter->clear($email, $request);

            $user->loadMissing('role');

            if ($user->setup_required) {
                $setupSession = $this->passwordSetupService->createForUser($user);
                $this->recordLoginPasswordSuccess($request, $user, 'password_setup_required');

                return response()->json([
                    'success' => true,
                    'message' => 'Account setup required.',
                    'data' => [
                        'next_step' => 'password_setup_required',
                        'setup_token' => $setupSession['plain_token'],
                        'expires_in' => $this->secondsUntil($setupSession['model']->expires_at),
                        'user' => [
                            'email' => $user->email,
                            'setup_required' => true,
                        ],
                    ],
                ], 200);
            }

            if (! $user->two_factor_enabled) {
                $setupSession = $this->twoFactorSetupService->createForUser($user);
                $this->recordLoginPasswordSuccess($request, $user, 'two_factor_setup_required');

                return response()->json([
                    'success' => true,
                    'message' => 'Two-factor setup required.',
                    'data' => [
                        'next_step' => 'two_factor_setup_required',
                        'two_factor_setup_token' => $setupSession['plain_token'],
                        'expires_in' => $this->secondsUntil($setupSession['model']->expires_at),
                        'user' => [
                            'email' => $user->email,
                            'setup_required' => false,
                            'two_factor_enabled' => false,
                        ],
                    ],
                ], 200);
            }

            $challenge = $this->authLoginChallengeService->createForUser($user, $request);
            $this->recordLoginPasswordSuccess($request, $user, 'otp_required');

            return response()->json([
                'success' => true,
                'message' => 'OTP verification required.',
                'data' => [
                    'next_step' => 'otp_required',
                    'login_challenge_id' => $challenge->getKey(),
                    'expires_in' => $this->secondsUntil($challenge->expires_at),
                    'user' => [
                        'email' => $user->email,
                    ],
                ],
            ], 200);
        } catch (Throwable $e) {
            report($e);

            $message = config('app.debug')
                ? $e->getMessage()
                : 'An unexpected error occurred.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
            ], 500);
        }
    }

    /**
     * Complete first-login password setup using a one-time setup token.
     */
    public function completePasswordSetup(CompletePasswordSetupRequest $request): JsonResponse
    {
        try {
            $user = $this->passwordSetupService->completeSetup(
                $request->validated('setup_token'),
                $request->validated('password'),
            );

            $setupSession = $this->twoFactorSetupService->createForUser($user);

            $this->authAuditService->record(
                AuthAuditService::EVENT_PASSWORD_SETUP_COMPLETED,
                AuthAuditService::STATUS_SUCCESS,
                $request,
                user: $user,
            );

            return response()->json([
                'success' => true,
                'message' => 'Password setup completed successfully.',
                'data' => [
                    'next_step' => 'two_factor_setup_required',
                    'two_factor_setup_token' => $setupSession['plain_token'],
                    'expires_in' => $this->secondsUntil($setupSession['model']->expires_at),
                    'user' => [
                        'email' => $user->email,
                        'setup_required' => false,
                        'two_factor_enabled' => false,
                    ],
                ],
            ], 200);
        } catch (InvalidPasswordSetupTokenException) {
            return response()->json([
                'success' => false,
                'message' => 'Password setup token is invalid or expired.',
                'data' => null,
            ], 422);
        } catch (Throwable $e) {
            report($e);

            $message = config('app.debug')
                ? $e->getMessage()
                : 'An unexpected error occurred.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
            ], 500);
        }
    }

    /**
     * Begin TOTP setup for a short-lived setup token.
     */
    public function startTwoFactorSetup(StartTwoFactorSetupRequest $request): JsonResponse
    {
        try {
            $started = $this->twoFactorSetupService->startSetup(
                $request->validated('two_factor_setup_token')
            );

            return response()->json([
                'success' => true,
                'message' => 'Two-factor setup started.',
                'data' => [
                    'next_step' => 'two_factor_setup_verify_required',
                    'manual_key' => $started['manual_key'],
                    'otpauth_uri' => $started['otpauth_uri'],
                    'expires_in' => $started['expires_in'],
                ],
            ], 200);
        } catch (InvalidTwoFactorSetupTokenException) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor setup token is invalid or expired.',
                'data' => null,
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Verify TOTP during first-login setup and issue authenticated session.
     */
    public function verifyTwoFactorSetup(VerifyTwoFactorSetupRequest $request): JsonResponse
    {
        try {
            $user = $this->twoFactorSetupService->verifySetup(
                $request->validated('two_factor_setup_token'),
                $request->validated('otp'),
            );

            $this->authAuditService->record(
                AuthAuditService::EVENT_TWO_FACTOR_SETUP_COMPLETED,
                AuthAuditService::STATUS_SUCCESS,
                $request,
                user: $user,
            );

            return $this->issueAuthenticatedSession($user, $request);
        } catch (InvalidTwoFactorSetupTokenException $e) {
            $message = str_contains($e->getMessage(), 'authentication code')
                ? 'The provided authentication code is invalid.'
                : 'Two-factor setup token is invalid or expired.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Verify OTP for a login challenge and issue authenticated session.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $user = $this->authLoginChallengeService->verify(
                $request->validated('login_challenge_id'),
                $request->validated('otp'),
            );

            $this->authAuditService->record(
                AuthAuditService::EVENT_OTP_SUCCESS,
                AuthAuditService::STATUS_SUCCESS,
                $request,
                user: $user,
                metadata: ['login_challenge_id' => $request->validated('login_challenge_id')],
            );

            return $this->issueAuthenticatedSession($user, $request);
        } catch (InvalidOtpChallengeException) {
            return response()->json([
                'success' => false,
                'message' => 'The authentication code is invalid or expired.',
                'data' => null,
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Rotate the refresh session and issue a new JWT access token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $forgetCookie = $this->refreshTokenService->forgetCookie();
        $plainToken = $this->refreshTokenService->readPlainTokenFromRequest($request);

        if ($plainToken === null) {
            $this->authAuditService->record(
                AuthAuditService::EVENT_REFRESH_FAILURE,
                AuthAuditService::STATUS_FAILURE,
                $request,
            );

            return $this->refreshFailureResponse($forgetCookie);
        }

        try {
            $validatedToken = $this->refreshTokenService->validatePlainToken($plainToken);
            $user = User::withTrashed()->with('role')->find($validatedToken->user_id);

            if ($user === null || $user->trashed() || $user->setup_required || ! $user->two_factor_enabled) {
                $this->refreshTokenService->revokeFromPlainToken($plainToken);

                if ($user !== null && $user->trashed()) {
                    $this->authAuditService->record(
                        AuthAuditService::EVENT_REFRESH_BLOCKED_DISABLED_USER,
                        AuthAuditService::STATUS_BLOCKED,
                        $request,
                        user: $user,
                    );
                } else {
                    $this->authAuditService->record(
                        AuthAuditService::EVENT_REFRESH_FAILURE,
                        AuthAuditService::STATUS_FAILURE,
                        $request,
                        user: $user,
                    );
                }

                return $this->refreshFailureResponse($forgetCookie);
            }

            $rotated = $this->refreshTokenService->rotatePlainToken($plainToken, $request);

            $this->syncAccessTokenTtl();
            $accessToken = auth('api')->login($user);

            $this->authAuditService->record(
                AuthAuditService::EVENT_REFRESH_SUCCESS,
                AuthAuditService::STATUS_SUCCESS,
                $request,
                user: $user,
                metadata: ['session_id' => $rotated['model']->getKey()],
            );

            return $this->buildAccessTokenResponse($user, $accessToken)
                ->withCookie($this->refreshTokenService->makeCookie($rotated['plain_token']));
        } catch (RefreshTokenReuseException $exception) {
            $token = $this->refreshTokenService->findByPlainToken($plainToken);
            $reuseUser = $token?->user;

            $this->authAuditService->record(
                AuthAuditService::EVENT_REFRESH_TOKEN_REUSE_DETECTED,
                AuthAuditService::STATUS_BLOCKED,
                $request,
                user: $reuseUser,
                metadata: ['session_id' => $token?->getKey()],
            );

            return $this->refreshFailureResponse($forgetCookie);
        } catch (InvalidRefreshTokenException) {
            $this->authAuditService->record(
                AuthAuditService::EVENT_REFRESH_FAILURE,
                AuthAuditService::STATUS_FAILURE,
                $request,
            );

            return $this->refreshFailureResponse($forgetCookie);
        } catch (Throwable $e) {
            report($e);

            $this->authAuditService->record(
                AuthAuditService::EVENT_REFRESH_FAILURE,
                AuthAuditService::STATUS_FAILURE,
                $request,
            );

            return $this->refreshFailureResponse($forgetCookie);
        }
    }

    /**
     * Return the currently authenticated API user.
     */
    public function me(): JsonResponse
    {
        $user = auth('api')->user()?->loadMissing('role');

        return response()->json([
            'success' => true,
            'message' => 'Authenticated user retrieved successfully.',
            'data' => [
                'user' => $user === null ? null : (new UserResource($user))->resolve(),
                'role' => $user?->role?->name,
            ],
        ]);
    }

    /**
     * Revoke the refresh session and invalidate JWT when present.
     */
    public function logout(Request $request): JsonResponse
    {
        $forgetCookie = $this->refreshTokenService->forgetCookie();

        $this->refreshTokenService->revokeFromPlainToken(
            $this->refreshTokenService->readPlainTokenFromRequest($request)
        );

        $authUser = auth('api')->user();

        if ($authUser !== null) {
            $this->authAuditService->record(
                AuthAuditService::EVENT_LOGOUT_SUCCESS,
                AuthAuditService::STATUS_SUCCESS,
                $request,
                user: $authUser,
            );
        }

        if ($request->bearerToken() !== null) {
            try {
                auth('api')->logout();
            } catch (JWTException) {
                // Tolerate expired or invalid JWT; refresh revocation and cookie clearing still succeed.
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
            'data' => null,
        ])->withCookie($forgetCookie);
    }

    private function recordLoginPasswordSuccess(Request $request, User $user, string $nextStep): void
    {
        $this->authAuditService->record(
            AuthAuditService::EVENT_LOGIN_PASSWORD_SUCCESS,
            AuthAuditService::STATUS_SUCCESS,
            $request,
            user: $user,
            metadata: ['next_step' => $nextStep],
        );
    }

    private function issueAuthenticatedSession(User $user, Request $request): JsonResponse
    {
        $this->syncAccessTokenTtl();
        $accessToken = auth('api')->login($user);
        $refreshSession = $this->refreshTokenService->createForUser($user, $request);

        return $this->buildAccessTokenResponse($user, $accessToken)
            ->withCookie($this->refreshTokenService->makeCookie($refreshSession['plain_token']));
    }

    private function buildAccessTokenResponse(User $user, string $accessToken): JsonResponse
    {
        $user->loadMissing('role');

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'access_token' => $accessToken,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->getTTL() * 60,
                'user' => (new UserResource($user))->resolve(),
                'role' => $user->role?->name,
            ],
        ], 200);
    }

    private function refreshFailureResponse(Cookie $forgetCookie): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Refresh session is invalid or expired.',
            'data' => null,
        ], 401)->withCookie($forgetCookie);
    }

    private function loginLockoutResponse(int $retryAfterSeconds): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Too many unsuccessful sign-in attempts. Please try again later.',
            'data' => [
                'retry_after' => $retryAfterSeconds,
            ],
        ], 429);
    }

    private function syncAccessTokenTtl(): void
    {
        config([
            'jwt.ttl' => (int) config('auth_security.access_token_ttl_minutes', config('jwt.ttl', 60)),
        ]);
    }

    private function secondsUntil(?\Illuminate\Support\Carbon $expiresAt): int
    {
        if ($expiresAt === null) {
            return 0;
        }

        return max(0, $expiresAt->diffInSeconds(now()));
    }
}
