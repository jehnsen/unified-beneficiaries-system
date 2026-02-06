<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SystemSetting Model
 *
 * Stores configurable system parameters for runtime configuration.
 * Enables Provincial admins to adjust fraud detection thresholds,
 * feature flags, and system behavior without code redeployment.
 *
 * Key Features:
 * - Type-safe value casting based on data_type
 * - Auto-generated UUID for public API
 * - Audit trail (created_by, updated_by)
 * - Soft deletes for safety
 *
 * @property int $id
 * @property string $uuid
 * @property string $key
 * @property string $value
 * @property string $data_type
 * @property string|null $description
 * @property array|null $validation_rules
 * @property string $category
 * @property bool $is_editable
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class SystemSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'key',
        'value',
        'data_type',
        'description',
        'validation_rules',
        'category',
        'is_editable',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'validation_rules' => 'array',
        'is_editable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot method - Auto-generate UUID for new records
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($setting) {
            // Auto-generate UUID for public-facing identifier
            if (empty($setting->uuid)) {
                $setting->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the route key name for Laravel route binding
     * Uses UUID instead of ID for public API security
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get value with type-safe casting based on data_type
     *
     * Converts the string-stored value to the appropriate type:
     * - integer: Casts to int
     * - float: Casts to float
     * - boolean: Validates and converts to bool
     * - json: Decodes to array
     * - string: Returns as-is
     *
     * @return mixed The casted value
     * @throws \RuntimeException If casting fails
     */
    public function getCastedValue(): mixed
    {
        try {
            return match ($this->data_type) {
                'integer' => (int) $this->value,
                'float' => (float) $this->value,
                'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                'json' => json_decode($this->value, true, 512, JSON_THROW_ON_ERROR),
                'string' => (string) $this->value,
                default => $this->value,
            };
        } catch (\Throwable $e) {
            Log::error('Failed to cast system setting value', [
                'key' => $this->key,
                'value' => $this->value,
                'data_type' => $this->data_type,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "Invalid value for setting '{$this->key}': {$e->getMessage()}"
            );
        }
    }

    /**
     * Set value with type-aware serialization
     *
     * Converts typed values to string storage format:
     * - json: JSON encodes arrays/objects
     * - boolean: Stores as '1' or '0'
     * - Others: String cast
     *
     * @param mixed $value The value to store
     * @return void
     * @throws \RuntimeException If serialization fails
     */
    public function setCastedValue(mixed $value): void
    {
        try {
            $this->value = match ($this->data_type) {
                'json' => json_encode($value, JSON_THROW_ON_ERROR),
                'boolean' => $value ? '1' : '0',
                default => (string) $value,
            };
        } catch (\Throwable $e) {
            Log::error('Failed to set system setting value', [
                'key' => $this->key,
                'value' => $value,
                'data_type' => $this->data_type,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "Cannot set value for setting '{$this->key}': {$e->getMessage()}"
            );
        }
    }

    /**
     * User who created this setting
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated this setting
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
