<?php

namespace App\Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings for the public site (logo, contact details, socials…).
 */
class CmsSiteDetail extends Model
{
    protected $table = 'cms_site_details';

    protected $guarded = ['id'];

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        $logo = static::getValue('logo');

        return empty($logo) ? null : asset('uploads/cms/'.$logo);
    }

    public function getLogoPathAttribute(): ?string
    {
        $logo = static::getValue('logo');

        return empty($logo) ? null : public_path('uploads/cms/'.$logo);
    }

    public static function getValue(string $key): ?string
    {
        return static::where('site_key', $key)->value('site_value');
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::updateOrCreate(['site_key' => $key], ['site_value' => $value]);
    }

    /**
     * All settings as a flat key/value array.
     *
     * @return array<string, string|null>
     */
    public static function allSettings(): array
    {
        return static::pluck('site_value', 'site_key')->all();
    }
}
