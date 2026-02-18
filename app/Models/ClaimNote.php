<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ClaimNote
 *
 * Stores individual investigation notes against a claim.
 * Notes are immutable once written (no updated_at, no SoftDeletes) —
 * an investigation trail must not be editable after the fact.
 */
class ClaimNote extends Model
{
    use LogsActivity;

    // Notes are immutable — disable updated_at entirely
    public $timestamps = false;

    // Manually manage created_at so the DB default (useCurrent) is used
    protected $dates = ['created_at'];

    protected $fillable = [
        'uuid',
        'claim_id',
        'user_id',
        'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Auto-generate UUID on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($claimNote) {
            if (empty($claimNote->uuid)) {
                $claimNote->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Configure activity logging.
     *
     * Note content is logged on creation only — since the model is immutable
     * there will never be an 'updated' event, so logOnlyDirty still works correctly.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['claim_id', 'user_id', 'note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('claim_notes');
    }

    /**
     * The claim this note belongs to.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * The user who wrote this note.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Use UUID for public-facing API routing.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
