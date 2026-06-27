<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\InvalidRefreshTokenException;
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
            $current = $this->refreshTokenService->validatePlainToken($plainToken);
            $rotated = $this->refreshTokenService->rotate($current, $request);
            $user = $current->user()->with('role')->firstOrFail();

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
     * Invalidate the current JWT and revoke the refresh session.
     */
    public function logout(Request $request): JsonResponse
    {
        $forgetCookie = $this->refreshTokenService->forgetCookie();
        $plainToken = $this->refreshTokenService->readPlainTokenFromRequest($request);

        if ($plainToken !== null) {
            $refreshToken = $this->refreshTokenService->findByPlainToken($plainToken);

            if ($refreshToken !== null && $refreshToken->isActive()) {
                $this->refreshTokenService->revoke($refreshToken);
            }
        }

        try {
            auth('api')->logout();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful.',
                'data' => null,
            ])->withCookie($forgetCookie);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired token.',
                'data' => null,
            ], 401)->withCookie($forgetCookie);
        }
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
