<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Collection;

/**
 * System Setting Repository Interface
 *
 * Defines the contract for system settings data access.
 * Implementations should handle CRUD operations, caching,
 * and audit trail for configuration management.
 */
interface SystemSettingRepositoryInterface
{
    /**
     * Get all system settings ordered by category and key
     *
     * @return Collection<int, SystemSetting>
     */
    public function all(): Collection;

    /**
     * Find a setting by its unique key
     *
     * @param string $key The setting key (e.g., 'RISK_THRESHOLD_DAYS')
     * @return SystemSetting|null
     */
    public function findByKey(string $key): ?SystemSetting;

    /**
     * Find a setting by its UUID
     *
     * @param string $uuid The UUID
     * @return SystemSetting|null
     */
    public function findByUuid(string $uuid): ?SystemSetting;

    /**
     * Update a setting's value by key
     *
     * Handles:
     * - is_editable validation
     * - updated_by tracking
     * - Audit logging
     *
     * @param string $key The setting key
     * @param mixed $value The new value
     * @return SystemSetting The updated setting
     * @throws \RuntimeException If setting is not editable
     */
    public function updateValue(string $key, mixed $value): SystemSetting;

    /**
     * Update a setting by UUID (full update)
     *
     * @param string $uuid The setting UUID
     * @param array $data The data to update
     * @return SystemSetting The updated setting
     * @throws \RuntimeException If setting is not editable
     */
    public function updateByUuid(string $uuid, array $data): SystemSetting;

    /**
     * Get all settings in a specific category
     *
     * @param string $category The category name
     * @return Collection<int, SystemSetting>
     */
    public function getByCategory(string $category): Collection;

    /**
     * Get all editable settings
     *
     * @return Collection<int, SystemSetting>
     */
    public function getAllEditable(): Collection;
}
