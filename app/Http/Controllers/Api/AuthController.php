<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public const LOCKOUT_THRESHOLD = 10;

    /**
     * Authenticate user and issue a Sanctum API token.
     *
     * Lockout policy: after LOCKOUT_THRESHOLD consecutive failures the account is locked
     * and requires explicit admin intervention via POST /api/v1/users/{uuid}/unlock.
     * The per-minute throttle (5 req/min in routes) still applies independently — both
     * layers must be defeated for an attacker to reach the lockout threshold quickly.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        // Look up the user record BEFORE Auth::attempt() so we can track failures
        // against a specific account rather than just an IP address.
        $candidate = User::where('email', $credentials['email'])->first();

        if ($candidate?->isLocked()) {
            return response()->json([
                'message' => 'Account locked due to too many failed login attempts. Contact your administrator.',
            ], 423); // 423 Locked
        }

        if (!Auth::attempt($credentials)) {
            if ($candidate) {
                $candidate->increment('failed_login_attempts');

                // Lock the account once the threshold is crossed
                if ($candidate->failed_login_attempts >= self::LOCKOUT_THRESHOLD) {
                    $candidate->update(['locked_at' => now()]);

                    Log::warning('Account locked after repeated failed logins', [
                        'user_id' => $candidate->id,
                        'email'   => $candidate->email,
                    ]);
                }
            }

            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();

            return response()->json([
                'message' => 'Your account has been deactivated. Contact your administrator.',
            ], 403);
        }

        // Successful authentication — clear the failure counter
        $user->update(['failed_login_attempts' => 0, 'locked_at' => null]);

        $deviceName = $request->input('device_name', 'ubis-api');
        $token      = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'data'    => new UserResource($user->load('municipality')),
            'token'   => $token,
            'message' => 'Login successful.',
        ]);
    }

    /**
     * Revoke the current access token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Revoke ALL tokens for the authenticated user.
     *
     * Use this when a staff member's account is believed to be compromised —
     * invalidates every active session across all devices immediately.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $count = $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'All sessions revoked successfully.',
            'revoked_tokens' => $count,
        ]);
    }

    /**
     * Return the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->load('municipality')),
        ]);
    }
}
