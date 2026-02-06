<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Http\Resources\SystemSettingResource;
use App\Interfaces\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use App\Services\ConfigurationService;
use Illuminate\Http\JsonResponse;

/**
 * Settings Controller
 *
 * Manages system configuration settings for Provincial admins.
 * Provides API endpoints for viewing and updating runtime configuration.
 *
 * Authorization: Provincial Staff + Admin role only (via Gate middleware)
 *
 * Endpoints:
 * - GET /api/admin/settings - List all settings grouped by category
 * - GET /api/admin/settings/{uuid} - Show single setting
 * - PUT /api/admin/settings/{uuid} - Update setting value/description
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SystemSettingRepositoryInterface $repository,
        private readonly ConfigurationService $configService
    ) {}

    /**
     * GET /api/admin/settings
     *
     * List all system settings grouped by category.
     * Includes audit trail (updater info) for accountability.
     *
     * Response:
     * {
     *   "data": {
     *     "fraud_detection": [...settings...],
     *     "system": [...settings...]
     *   },
     *   "meta": {
     *     "total_settings": 4,
     *     "categories": ["fraud_detection", "system"]
     *   }
     * }
     */
    public function index(): JsonResponse
    {
        // Get all settings with updater relationship for audit trail
        $settings = $this->repository->all()->load('updater');

        // Group by category for organized display
        $grouped = $settings->groupBy('category')->map(function ($group) {
            return SystemSettingResource::collection($group);
        });

        return response()->json([
            'data' => $grouped,
            'meta' => [
                'total_settings' => $settings->count(),
                'categories' => $settings->pluck('category')->unique()->values(),
            ],
        ]);
    }

    /**
     * GET /api/admin/settings/{setting:uuid}
     *
     * Show a single setting with full details.
     * Includes both creator and updater for complete audit trail.
     *
     * @param SystemSetting $setting Injected via route binding (by UUID)
     */
    public function show(SystemSetting $setting): JsonResponse
    {
        // Load relationships for audit trail
        $setting->load('updater', 'creator');

        return response()->json([
            'data' => new SystemSettingResource($setting),
        ]);
    }

    /**
     * PUT /api/admin/settings/{setting:uuid}
     *
     * Update a setting's value and/or description.
     *
     * Features:
     * - Validates value based on dynamic validation rules
     * - Checks is_editable flag
     * - Tracks updated_by for audit trail
     * - Flushes cache for immediate effect
     *
     * @param SystemSetting $setting Injected via route binding (by UUID)
     * @param UpdateSystemSettingRequest $request Validated request
     */
    public function update(
        SystemSetting $setting,
        UpdateSystemSettingRequest $request
    ): JsonResponse {
        // Check if setting is editable (system-critical settings are locked)
        if (!$setting->is_editable) {
            return response()->json([
                'message' => 'This setting cannot be modified.',
                'errors' => [
                    'setting' => [
                        'System-critical settings are read-only for security and stability.',
                    ],
                ],
            ], 422);
        }

        $validated = $request->validated();

        // Update via repository (handles audit trail + DB transaction)
        $updated = $this->repository->updateByUuid($setting->uuid, [
            'value' => $validated['value'],
            'description' => $validated['description'] ?? $setting->description,
            'updated_by' => auth()->id(),
        ]);

        // Flush entire settings cache to ensure consistency
        // (Alternative: invalidate only this specific key)
        $this->configService->flushCache();

        // Return updated setting with updater info
        return response()->json([
            'data' => new SystemSettingResource($updated->load('updater')),
            'message' => 'Setting updated successfully.',
        ]);
    }
}
