<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Interfaces\SystemSettingRepositoryInterface;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent System Setting Repository
 *
 * Concrete implementation of SystemSettingRepositoryInterface.
 * Handles database operations for system configuration settings.
 *
 * Features:
 * - Transaction-based updates for data integrity
 * - Row locking to prevent concurrent update conflicts
 * - Audit trail logging via log_audit() helper
 * - is_editable enforcement
 */
class EloquentSystemSettingRepository implements SystemSettingRepositoryInterface
{
    /**
     * Get all system settings ordered by category and key
     */
    public function all(): Collection
    {
        return SystemSetting::query()
            ->orderBy('category')
            ->orderBy('key')
            ->get();
    }

    /**
     * Find a setting by its unique key
     */
    public function findByKey(string $key): ?SystemSetting
    {
        return SystemSetting::where('key', $key)->first();
    }

    /**
     * Find a setting by its UUID
     */
    public function findByUuid(string $uuid): ?SystemSetting
    {
        return SystemSetting::where('uuid', $uuid)->first();
    }

    /**
     * Update a setting's value by key
     *
     * This is the primary method for ConfigurationService to update values.
     * Uses transactions and row locking for concurrent safety.
     *
     * @throws \RuntimeException If setting is not editable
     */
    public function updateValue(string $key, mixed $value): SystemSetting
    {
        return DB::transaction(function () use ($key, $value) {
            // Lock row for update to prevent concurrent modifications
            $setting = SystemSetting::where('key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            // Enforce editability
            if (!$setting->is_editable) {
                throw new \RuntimeException("Setting '{$key}' is not editable.");
            }

            // Store old value for audit trail
            $oldValue = $setting->value;

            // Use type-safe setter
            $setting->setCastedValue($value);

            // Track who updated
            $setting->updated_by = auth()->id();

            $setting->save();

            // Log audit trail using UBIS helper
            log_audit('UPDATE_SETTING', 'SystemSetting', $setting->id, [
                'key' => $key,
                'old_value' => $oldValue,
                'new_value' => $value,
            ]);

            return $setting->fresh();
        });
    }

    /**
     * Update a setting by UUID (full update)
     *
     * Used by admin API to update value + description
     *
     * @throws \RuntimeException If setting is not editable
     */
    public function updateByUuid(string $uuid, array $data): SystemSetting
    {
        return DB::transaction(function () use ($uuid, $data) {
            // Lock row for update
            $setting = SystemSetting::where('uuid', $uuid)
                ->lockForUpdate()
                ->firstOrFail();

            // Enforce editability
            if (!$setting->is_editable) {
                throw new \RuntimeException("Setting '{$setting->key}' is not editable.");
            }

            // Store old value for audit trail
            $oldValue = $setting->value;

            // Update value if provided
            if (isset($data['value'])) {
                $setting->setCastedValue($data['value']);
            }

            // Update description if provided
            if (isset($data['description'])) {
                $setting->description = $data['description'];
            }

            // Track who updated
            if (isset($data['updated_by'])) {
                $setting->updated_by = $data['updated_by'];
            }

            $setting->save();

            // Log audit trail
            log_audit('UPDATE_SETTING', 'SystemSetting', $setting->id, [
                'key' => $setting->key,
                'old_value' => $oldValue,
                'new_value' => $data['value'] ?? $oldValue,
                'updated_fields' => array_keys($data),
            ]);

            return $setting->fresh();
        });
    }

    /**
     * Get all settings in a specific category
     */
    public function getByCategory(string $category): Collection
    {
        return SystemSetting::where('category', $category)
            ->orderBy('key')
            ->get();
    }

    /**
     * Get all editable settings
     */
    public function getAllEditable(): Collection
    {
        return SystemSetting::where('is_editable', true)
            ->orderBy('category')
            ->orderBy('key')
            ->get();
    }
}
