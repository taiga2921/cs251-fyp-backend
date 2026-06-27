<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompletePasswordSetupRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\InvalidPasswordSetupTokenException;
use App\Services\Auth\InvalidRefreshTokenException;
use App\Services\Auth\PasswordSetupService;
use App\Services\Auth\RefreshTokenReuseException;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly PasswordSetupService $passwordSetupService,
    ) {}

    /**
     * Issue a JWT for API access (email / password).
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

            $this->syncAccessTokenTtl();

            $credentials = $validator->validated();
            $token = auth('api')->attempt($credentials);

            if ($token === false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials.',
                    'data' => null,
                ], 401);
            }

            $user = auth('api')->user()?->loadMissing('role');

            if ($user === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials.',
                    'data' => null,
                ], 401);
            }

            if ($user->setup_required) {
                auth('api')->logout();

                $setupSession = $this->passwordSetupService->createForUser($user);
                $expiresAt = $setupSession['model']->expires_at;

                return response()->json([
                    'success' => true,
                    'message' => 'Account setup required.',
                    'data' => [
                        'next_step' => 'password_setup_required',
                        'setup_token' => $setupSession['plain_token'],
                        'expires_in' => $expiresAt !== null ? max(0, $expiresAt->diffInSeconds(now())) : 0,
                        'user' => [
                            'email' => $user->email,
                            'setup_required' => true,
                        ],
                    ],
                ], 200);
            }

            $refreshSession = $this->refreshTokenService->createForUser($user, $request);

            return $this->buildAccessTokenResponse($user, $token)
                ->withCookie($this->refreshTokenService->makeCookie($refreshSession['plain_token']));
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

            return response()->json([
                'success' => true,
                'message' => 'Password setup completed successfully.',
                'data' => [
                    'next_step' => 'two_factor_setup_required',
                    'user' => (new UserResource($user))->resolve(),
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
     * Rotate the refresh session and issue a new JWT access token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $forgetCookie = $this->refreshTokenService->forgetCookie();
        $plainToken = $this->refreshTokenService->readPlainTokenFromRequest($request);

        if ($plainToken === null) {
            return $this->refreshFailureResponse($forgetCookie);
        }

        try {
            $validatedToken = $this->refreshTokenService->validatePlainToken($plainToken);
            $user = $validatedToken->user()->with('role')->firstOrFail();

            if ($user->setup_required) {
                $this->refreshTokenService->revokeFromPlainToken($plainToken);

                return $this->refreshFailureResponse($forgetCookie);
            }

            $rotated = $this->refreshTokenService->rotatePlainToken($plainToken, $request);

            $this->syncAccessTokenTtl();
            $accessToken = auth('api')->login($user);

            return $this->buildAccessTokenResponse($user, $accessToken)
                ->withCookie($this->refreshTokenService->makeCookie($rotated['plain_token']));
        } catch (RefreshTokenReuseException|InvalidRefreshTokenException) {
            return $this->refreshFailureResponse($forgetCookie);
        } catch (Throwable $e) {
            report($e);

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
     *
     * Public route: must clear HttpOnly refresh cookie even when the bearer token is missing or expired.
     */
    public function logout(Request $request): JsonResponse
    {
        $forgetCookie = $this->refreshTokenService->forgetCookie();

        $this->refreshTokenService->revokeFromPlainToken(
            $this->refreshTokenService->readPlainTokenFromRequest($request)
        );

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

    private function syncAccessTokenTtl(): void
    {
        config([
            'jwt.ttl' => (int) config('auth_security.access_token_ttl_minutes', config('jwt.ttl', 60)),
        ]);
    }
}
