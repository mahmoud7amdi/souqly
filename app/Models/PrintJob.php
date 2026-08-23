<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A job waiting for the local print agent to pick up and print.
 *
 * Lifecycle: pending -> printing -> done | failed
 */
class PrintJob extends Model
{
    protected $fillable = [
        'business_id',
        'location_id',
        'status',
        'payload',
        'error_message',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
