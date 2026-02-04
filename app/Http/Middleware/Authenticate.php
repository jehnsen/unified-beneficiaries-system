<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * Custom Authenticate middleware for API routes.
 *
 * Overrides Laravel's default behavior of redirecting to 'login' route
 * and instead returns JSON 401 response for unauthenticated API requests.
 */
class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * For API routes, return null to trigger JSON response instead of redirect.
     */
    protected function redirectTo(Request $request): ?string
    {
        // Always return null for API requests to get JSON 401 response
        return null;
    }
}
