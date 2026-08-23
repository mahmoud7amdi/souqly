<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A movement on a payment account (deposit, withdrawal, transfer or the
 * account side of a transaction payment).
 */
class AccountTransaction extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'operation_date' => 'datetime',
            'amount' => 'float',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function transaction_payment(): BelongsTo
    {
        return $this->belongsTo(TransactionPayment::class, 'transaction_payment_id');
    }

    /** The mirror row on the other account of a fund transfer. */
    public function transfer_transaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class, 'transfer_transaction_id');
    }

    public function created_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }
}
