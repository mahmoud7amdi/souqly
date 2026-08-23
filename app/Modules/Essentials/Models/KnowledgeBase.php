<?php

namespace App\Modules\Essentials\Models;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A knowledge base article. Articles can nest (parent_id) and be shared
 * publicly, privately, or with named users.
 */
class KnowledgeBase extends Model
{
    protected $table = 'essentials_kb';

    protected $guarded = ['id'];

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function scopeRootLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class, 'parent_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'essentials_kb_users',
            'kb_id',
            'user_id'
        );
    }

    public function created_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVisibleTo(User $user): bool
    {
        if ($this->share_with === 'public') {
            return true;
        }

        if ($this->created_by === $user->id) {
            return true;
        }

        return $this->share_with === 'only_with'
            && $this->users()->whereKey($user->id)->exists();
    }
}
