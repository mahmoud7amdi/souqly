<?php

namespace App\Modules\Essentials\Models;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A file shared with specific users or roles.
 */
class Document extends Model
{
    protected $table = 'essentials_documents';

    protected $guarded = ['id'];

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class, 'document_id');
    }

    /**
     * Visible to the given user if they uploaded it, or it is shared with
     * them directly or through one of their roles.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        $roleIds = $user->roles->pluck('id')->all();

        return $this->shares()
            ->where(function ($q) use ($user, $roleIds) {
                $q->where(fn ($sub) => $sub->where('value_type', 'user')->where('value', $user->id))
                    ->orWhere(fn ($sub) => $sub->where('value_type', 'role')->whereIn('value', $roleIds));
            })
            ->exists();
    }
}
