<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier's till session.
 */
class CashRegister extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'denominations' => 'array',
            'closed_at' => 'datetime',
            'closing_amount' => 'float',
        ];
    }

    public function cash_register_transactions(): HasMany
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
