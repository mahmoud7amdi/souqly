<?php

namespace App\Modules\Essentials\Models;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EssentialsTodoComment extends Model
{
    protected $table = 'essentials_todo_comments';

    protected $guarded = ['id'];

    public function added_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comment_by');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ToDo::class, 'task_id');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }
}
