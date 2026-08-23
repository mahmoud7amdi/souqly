<?php

namespace App\Modules\AssetManagement\Models;

use App\Models\Media;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class AssetMaintenance extends Model
{
    protected $table = 'asset_maintenances';

    protected $guarded = ['id'];

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            'scheduled' => __('assetmanagement.scheduled'),
            'in_progress' => __('assetmanagement.in_progress'),
            'completed' => __('assetmanagement.completed'),
            'cancelled' => __('assetmanagement.cancelled'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function priorities(): array
    {
        return [
            'low' => __('essentials.low'),
            'medium' => __('essentials.medium'),
            'high' => __('essentials.high'),
            'urgent' => __('essentials.urgent'),
        ];
    }
}
