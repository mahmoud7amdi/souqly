<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceLayout extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'product_custom_fields' => 'array',
            'contact_custom_fields' => 'array',
            'location_custom_fields' => 'array',
            'common_settings' => 'array',
            'qr_code_fields' => 'array',
            'table_tax_headings' => 'array',
            'module_info' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(BusinessLocation::class);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }
}
