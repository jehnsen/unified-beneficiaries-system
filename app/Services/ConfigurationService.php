<?php

declare(strict_types=1);

namespace App\Services;

use App\Interfaces\SystemSettingRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Configuration Service
 *
 * Provides cached, type-safe access to system settings.
 * This service replaces hardcoded constants throughout the application,
 * enabling runtime configuration by Provincial admins.
 *
 * Features:
 * - Cache-first strategy with 1-hour TTL
 * - Type-safe getters (getInt, getFloat, getBool)
 * - Fallback defaults if setting missing
 * - Warning logging for missing settings
 * - Cache invalidation on updates
 *
 * Usage:
 *   $configService->getInt('RISK_THRESHOLD_DAYS', 90)
 *   $configService->set('RISK_THRESHOLD_DAYS', 120)
 */
class ConfigurationService
{
    /**
     * Cache TTL in seconds (1 hour)
     * High read frequency, low write frequency in Provincial admin context
     */
    private const CACHE_TTL = 3600;

    /**
     * Cache key prefix to avoid collisions
     */
    private const CACHE_PREFIX = 'settings:';

    public function __construct(
        private readonly SystemSettingRepositoryInterface $repository
    ) {}

    /**
     * Get a configuration value with type-safe casting
     *
     * Uses cache-first strategy:
     * 1. Check cache
     * 2. If miss, query database
     * 3. Cache result for CACHE_TTL seconds
     * 4. If not found, return default and log warning
     *
     * @param string $key The setting key (e.g., 'RISK_THRESHOLD_DAYS')
     * @param mixed $default Fallback value if setting not found
     * @return mixed The casted value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::tags(['system_settings'])->remember(
            self::CACHE_PREFIX . $key,
            self::CACHE_TTL,
            function () use ($key, $default) {
                $setting = $this->repository->findByKey($key);

                if (!$setting) {
                    // Log warning for debugging incomplete seeding
                    Log::warning("System setting '{$key}' not found. Using fallback default.", [
                        'key' => $key,
                        'default' => $default,
                        'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
                    ]);

                    return $default;
                }

                return $setting->getCastedValue();
            }
        );
    }

    /**
     * Set a configuration value and invalidate cache
     *
     * Updates the setting in database and immediately invalidates cache
     * to ensure consistency across requests.
     *
     * @param string $key The setting key
     * @param mixed $value The new value
     * @return void
     * @throws \RuntimeException If setting is not editable
     */
    public function set(string $key, mixed $value): void
    {
        $this->repository->updateValue($key, $value);
        $this->invalidateCache($key);
    }

    /**
     * Get integer value (convenience method)
     *
     * @param string $key The setting key
     * @param int $default Fallback integer value
     * @return int
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /**
     * Get float value (convenience method)
     *
     * @param string $key The setting key
     * @param float $default Fallback float value
     * @return float
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    /**
     * Get boolean value (convenience method)
     *
     * @param string $key The setting key
     * @param bool $default Fallback boolean value
     * @return bool
     */
    public function getBool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    /**
     * Get string value (convenience method)
     *
     * @param string $key The setting key
     * @param string $default Fallback string value
     * @return string
     */
    public function getString(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    /**
     * Invalidate cache for a specific setting
     *
     * Called automatically after updates to ensure fresh data.
     *
     * @param string $key The setting key
     * @return void
     */
    private function invalidateCache(string $key): void
    {
        Cache::tags(['system_settings'])->forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Flush entire settings cache
     *
     * Useful after bulk updates or when cache corruption suspected.
     * The SettingsController calls this after successful updates.
     *
     * @return void
     */
    public function flushCache(): void
    {
        Cache::tags(['system_settings'])->flush();
    }
}
