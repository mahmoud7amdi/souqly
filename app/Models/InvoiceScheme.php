<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class InvoiceScheme extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(): array
    {
        return static::orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Build the next invoice number for this scheme and bump the counter.
     *
     * `scheme_type = year` prefixes with the 4-digit year; `number_type`
     * may be `sequential` (default) or `random`.
     */
    public function generateNumber(): string
    {
        $prefix = (string) $this->prefix;

        if ($this->scheme_type === 'year') {
            $prefix = date('Y').$prefix;
        }

        if ($this->number_type === 'random') {
            return $prefix.random_int(
                (int) str_pad('1', max(1, (int) $this->total_digits), '0'),
                (int) str_pad('9', max(1, (int) $this->total_digits), '9')
            );
        }

        $number = (int) $this->start_number + (int) $this->invoice_count;

        $this->invoice_count = (int) $this->invoice_count + 1;
        $this->save();

        return $prefix.str_pad((string) $number, (int) $this->total_digits, '0', STR_PAD_LEFT);
    }
}
