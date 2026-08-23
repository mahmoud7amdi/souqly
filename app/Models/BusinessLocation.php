<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessLocation extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'featured_products' => 'array',
            'default_payment_accounts' => 'array',
            'is_active' => 'boolean',
            'print_receipt_on_invoice' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function price_group(): BelongsTo
    {
        return $this->belongsTo(SellingPriceGroup::class, 'selling_price_group_id');
    }

    public function invoice_scheme(): BelongsTo
    {
        return $this->belongsTo(InvoiceScheme::class, 'invoice_scheme_id');
    }

    public function sale_invoice_scheme(): BelongsTo
    {
        return $this->belongsTo(InvoiceScheme::class, 'sale_invoice_scheme_id');
    }

    public function invoice_layout(): BelongsTo
    {
        return $this->belongsTo(InvoiceLayout::class, 'invoice_layout_id');
    }

    public function sale_invoice_layout(): BelongsTo
    {
        return $this->belongsTo(InvoiceLayout::class, 'sale_invoice_layout_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_id');
    }

    /**
     * Multi-line postal address.
     */
    public function getLocationAddressAttribute(): string
    {
        $parts = array_filter([
            $this->landmark,
            $this->city,
            $this->state,
            $this->country,
            $this->zip_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Locations the current user is permitted to work in.
     *
     * @return array<int, string>
     */
    public static function forDropdown(bool $addAll = false, bool $onlyActive = true): array
    {
        $query = static::query();

        if ($onlyActive) {
            $query->where('is_active', 1);
        }

        $locations = $query->orderBy('name')->get()->filter(function ($location) {
            return auth()->user()?->can('access_all_locations')
                || auth()->user()?->can('location.'.$location->id);
        });

        $result = $locations->pluck('name', 'id')->all();

        if ($addAll) {
            $result = ['' => __('lang_v1.all')] + $result;
        }

        return $result;
    }

    /**
     * Ids of the locations the current user may access.
     *
     * @return array<int, int>|string  'all' when unrestricted
     */
    public static function permittedLocations(): array|string
    {
        if (auth()->user()?->can('access_all_locations')) {
            return 'all';
        }

        return static::all()
            ->filter(fn ($l) => auth()->user()?->can('location.'.$l->id))
            ->pluck('id')
            ->all();
    }
}
