<?php

namespace App\Modules\ProductCatalogue\Models;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\SellingPriceGroup;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A tokenised, shareable catalogue link scoped to one customer — optionally
 * showing that customer's own price group.
 */
class ClientCatalogue extends Model
{
    use SoftDeletes;

    protected $table = 'product_catalogue_client_catalogues';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function ($catalogue) {
            if (empty($catalogue->token)) {
                $catalogue->token = static::generateToken();
            }
        });
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(SellingPriceGroup::class, 'price_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::withTrashed()->where('token', $token)->exists());

        return $token;
    }

    public function regenerateToken(): void
    {
        $this->token = static::generateToken();
        $this->save();
    }

    public function getPublicUrlAttribute(): string
    {
        return route('catalogue.client', ['token' => $this->token]);
    }
}
