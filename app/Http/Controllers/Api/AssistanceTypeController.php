<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AssistanceTypeController extends Controller
{
    /**
     * Return reference data for assistance types and claim statuses.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'assistance_types' => get_assistance_types(),
                'claim_statuses' => get_claim_statuses(),
            ],
        ]);
    }
}
