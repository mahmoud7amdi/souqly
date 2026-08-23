<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TransactionPayment extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'datetime',
            'amount' => 'float',
            'is_return' => 'boolean',
            'is_advance' => 'boolean',
            'paid_through_link' => 'boolean',
        ];
    }

    public function getDocumentPathAttribute(): ?string
    {
        return empty($this->document)
            ? null
            : asset('uploads/documents/'.$this->document);
    }

    public function getDocumentNameAttribute(): ?string
    {
        return empty($this->document)
            ? null
            : preg_replace('/^\d+_/', '', $this->document);
    }

    /**
     * The payment method as a person reads it.
     *
     * Not `__('lang_v1.'.$this->method)`: the custom methods are stored as
     * `custom_pay_1` but translated under `custom_payment_1`, so the obvious
     * version prints the raw key for seven of the thirteen. Every screen that
     * lists payments — the ledger, an account, a register session — needs this
     * label, so it lives here rather than in each of them.
     */
    public function getMethodLabelAttribute(): string
    {
        $key = \App\Support\TransactionTypes::paymentMethods()[$this->method] ?? null;

        return $key ? __($key) : (string) $this->method;
    }

    public function payment_account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function created_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'payment_for');
    }

    /**
     * When a contact's whole due is settled in one go, a parent payment row
     * is created and one child row per invoice it covers.
     */
    public function child_payments(): HasMany
    {
        return $this->hasMany(TransactionPayment::class, 'parent_id');
    }

    public function parent_payment(): BelongsTo
    {
        return $this->belongsTo(TransactionPayment::class, 'parent_id');
    }

    public function denominations(): MorphMany
    {
        return $this->morphMany(CashDenomination::class, 'model');
    }
}
