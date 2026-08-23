<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Business extends Model
{
    protected $table = 'business';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'ref_no_prefixes' => 'array',
            'enabled_modules' => 'array',
            'email_settings' => 'array',
            'sms_settings' => 'array',
            'common_settings' => 'array',
            'pos_settings' => 'array',
            'custom_labels' => 'array',
            'weighing_scale_setting' => 'array',
            'essentials_settings' => 'array',
            'asset_settings' => 'array',
            'is_active' => 'boolean',
            'enable_rp' => 'boolean',
            'enable_product_expiry' => 'boolean',
            'enable_lot_number' => 'boolean',
            'enable_sub_units' => 'boolean',
            'enable_racks' => 'boolean',
            'enable_row' => 'boolean',
            'enable_position' => 'boolean',
            'enable_tooltip' => 'boolean',
            'enable_brand' => 'boolean',
            'enable_category' => 'boolean',
            'enable_sub_category' => 'boolean',
            'enable_price_tax' => 'boolean',
            'enable_purchase_status' => 'boolean',
            'enable_inline_tax' => 'boolean',
            'purchase_in_diff_currency' => 'boolean',
            'enable_editing_product_from_purchase' => 'boolean',
            'item_addition_method' => 'boolean',
        ];
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'owner_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function purchase_currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'purchase_currency_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(\App\Modules\Superadmin\Models\Subscription::class);
    }

    /**
     * Single-line postal address of the business' first location.
     */
    public function getBusinessAddressAttribute(): string
    {
        $location = $this->locations()->first();

        if (empty($location)) {
            return '';
        }

        return $location->location_address;
    }

    /**
     * True when the named optional module is switched on for this tenant.
     */
    public function isModuleEnabled(string $module): bool
    {
        return in_array($module, (array) $this->enabled_modules, true);
    }
}
