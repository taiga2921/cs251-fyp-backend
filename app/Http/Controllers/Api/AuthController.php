<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Throwable;

class AuthController extends Controller
{
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

            return response()->json([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'access_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => auth('api')->getTTL() * 60,
                    'user' => $user === null ? null : (new UserResource($user))->resolve(),
                    'role' => $user?->role?->name,
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
     * Invalidate the current JWT and log the user out.
     */
    public function logout(): JsonResponse
    {
        try {
            auth('api')->logout();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful.',
                'data' => null,
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired token.',
                'data' => null,
            ], 401);
        }
    }
}
