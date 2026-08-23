<?php

namespace App\Modules\Essentials\Models;

use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EssentialsEmployeeDocument extends Model
{
    protected $table = 'essentials_employee_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'is_verified' => 'boolean',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString());
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [
                now()->toDateString(),
                now()->addDays($days)->toDateString(),
            ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getIsExpiredAttribute(): bool
    {
        return ! empty($this->expiry_date) && $this->expiry_date->isPast();
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return ! empty($this->expiry_date)
            && ! $this->expiry_date->isPast()
            && $this->expiry_date->diffInDays(now()) <= 30;
    }

    public function getDocumentTypeLabelAttribute(): string
    {
        return __('essentials.'.$this->document_type);
    }

    /**
     * @return array<string, string>
     */
    public static function documentTypes(): array
    {
        return [
            'contract' => __('essentials.contract'),
            'id_proof' => __('essentials.id_proof'),
            'passport' => __('essentials.passport'),
            'visa' => __('essentials.visa'),
            'certificate' => __('essentials.certificate'),
            'resume' => __('essentials.resume'),
            'medical' => __('essentials.medical'),
            'other' => __('essentials.other'),
        ];
    }
}
