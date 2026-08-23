<?php

namespace App\Modules\Accounting\Models;

use App\Models\Currency;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the chart of accounts.
 */
class ChartOfAccount extends Model
{
    protected $table = 'chart_of_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'float',
            'allow_manual' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function scopeForBusiness(Builder $query, ?int $businessId = null): Builder
    {
        return $query->where('business_id', $businessId ?? Tenancy::id());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', 1);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('account_type', $type);
    }

    public function journal_entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id')
            ->withDefault(['name' => '—']);
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class)->withDefault();
    }

    public function account_subtype(): BelongsTo
    {
        return $this->belongsTo(AccountSubtype::class)->withDefault();
    }

    public function account_detail_type(): BelongsTo
    {
        return $this->belongsTo(AccountDetailType::class, 'detail_type_id')->withDefault();
    }

    /**
     * Balance = opening balance + net of all posted entries, signed by the
     * account's natural side (assets/expenses are debit-positive).
     */
    public function getCurrentBalanceAttribute(): float
    {
        $debit = (float) $this->journal_entries()->notReversed()->sum('debit');
        $credit = (float) $this->journal_entries()->notReversed()->sum('credit');

        $net = in_array($this->account_type, ['asset', 'expense'], true)
            ? $debit - $credit
            : $credit - $debit;

        return round((float) $this->opening_balance + $net, 4);
    }

    public function getNameWithSubtypeAttribute(): string
    {
        $subtype = $this->account_subtype->name ?? null;

        return $this->name.($subtype ? ' ('.$subtype.')' : '');
    }

    /**
     * @return array<int, string>
     */
    public static function forDropdown(?string $type = null): array
    {
        $query = static::forBusiness()->active();

        if (! is_null($type)) {
            $query->where('account_type', $type);
        }

        return $query->orderBy('gl_code')
            ->get()
            ->mapWithKeys(fn ($a) => [
                $a->id => ($a->gl_code ? $a->gl_code.' - ' : '').$a->name,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function accountTypes(): array
    {
        return [
            'asset' => __('accounting.asset'),
            'liability' => __('accounting.liability'),
            'equity' => __('accounting.equity'),
            'income' => __('accounting.income'),
            'expense' => __('accounting.expense'),
        ];
    }
}
