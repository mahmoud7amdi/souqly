<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bank or cash account that payments can be posted to.
 */
class Account extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['account_details' => 'array', 'is_closed' => 'boolean'];
    }

    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->where('is_closed', 0);
    }

    /**
     * Excludes capital accounts, which cannot receive normal payments.
     */
    public function scopeNotCapital(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('account_type', '!=', 'capital')->orWhereNull('account_type');
        });
    }

    public function account_type(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function account_transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    /**
     * The catalogue type's name — "Bank", "Till", whatever the business created.
     *
     * `$account->account_type` cannot reach the relation above: `accounts`
     * carries a real `account_type` enum column too (saving_current / capital),
     * and Eloquent resolves an existing attribute before a relation of the same
     * name. So `$account->account_type` is the accounting kind — a string — and
     * `->name` on it silently yields null under `??`, which is exactly the sort
     * of bug that renders as an em dash and never gets noticed.
     *
     * The relation keeps its name because the schema and every eager load in the
     * codebase use it. The way to read the label is here, once.
     */
    public function getAccountTypeNameAttribute(): ?string
    {
        return $this->getRelationValue('account_type')?->name;
    }

    /**
     * Current balance = credits − debits.
     */
    public function getBalanceAttribute(): float
    {
        $credit = (float) $this->account_transactions()->where('type', 'credit')->sum('amount');
        $debit = (float) $this->account_transactions()->where('type', 'debit')->sum('amount');

        return round($credit - $debit, 4);
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(bool $prependNone = true): array
    {
        $accounts = static::notClosed()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($a) => [
                $a->id => $a->name.($a->account_number ? ' - '.$a->account_number : ''),
            ])
            ->all();

        if ($prependNone) {
            $accounts = ['' => __('lang_v1.none')] + $accounts;
        }

        return $accounts;
    }
}
