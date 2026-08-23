<?php

namespace App\Modules\Essentials\Models;

use App\Models\Media;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A task, optionally assigned to several users, with comments and files.
 */
class ToDo extends Model
{
    protected $table = 'essentials_to_dos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'end_date' => 'date',
            'is_completed' => 'boolean',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('end_date')
            ->where('end_date', '<', now()->toDateString())
            ->whereNotIn('status', ['completed', 'closed']);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'essentials_todos_users',
            'todo_id',
            'user_id'
        );
    }

    public function assigned_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EssentialsTodoComment::class, 'task_id')->orderBy('id', 'desc');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            'new' => __('essentials.new'),
            'in_progress' => __('essentials.in_progress'),
            'on_hold' => __('essentials.on_hold'),
            'completed' => __('essentials.completed'),
            'closed' => __('essentials.closed'),
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

    /**
     * @return array<string, mixed>
     */
    public function getLogPropertiesAttribute(): array
    {
        return [
            'id' => $this->id,
            'task' => $this->task,
            'status' => $this->status,
            'priority' => $this->priority,
        ];
    }
}
