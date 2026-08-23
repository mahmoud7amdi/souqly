<?php

namespace App\Modules\Cms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A public page, blog post or testimonial.
 */
class CmsPage extends Model
{
    protected $table = 'cms_pages';

    protected $guarded = ['id'];

    protected $appends = ['slug', 'feature_image_url'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', 1);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function getSlugAttribute(): string
    {
        return Str::slug((string) $this->title);
    }

    public function getFeatureImageUrlAttribute(): ?string
    {
        return empty($this->feature_image)
            ? null
            : asset('uploads/cms/'.$this->feature_image);
    }

    public function getFeatureImagePathAttribute(): ?string
    {
        return empty($this->feature_image)
            ? null
            : public_path('uploads/cms/'.$this->feature_image);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function pageMeta(): HasMany
    {
        return $this->hasMany(CmsPageMeta::class, 'cms_page_id', 'id');
    }

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'page' => __('cms.page'),
            'blog' => __('cms.blog'),
            'testimonial' => __('cms.testimonial'),
        ];
    }
}
