<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A file attached polymorphically to any model.
 */
class Media extends Model
{
    protected $table = 'media';

    protected $guarded = ['id'];

    protected $appends = ['display_name', 'display_url'];

    /**
     * Original filename, with the upload timestamp prefix stripped.
     */
    public function getDisplayNameAttribute(): string
    {
        return preg_replace('/^\d+_/', '', (string) $this->file_name);
    }

    public function getDisplayUrlAttribute(): string
    {
        return asset('uploads/media/'.$this->file_name);
    }

    public function getDisplayPathAttribute(): string
    {
        return public_path('uploads/media/'.$this->file_name);
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo('model');
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploaded_by_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * True when the file is a displayable image.
     */
    public function isImage(): bool
    {
        return in_array(
            strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            true
        );
    }
}
