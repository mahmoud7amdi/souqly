<?php

namespace App\Models;

use App\Support\TransactionTypes;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The single ledger for every movement in the system.
 *
 * @see \App\Support\TransactionTypes
 */
class Transaction extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'delivery_date' => 'datetime',
            'recur_stopped_on' => 'datetime',
            'import_time' => 'datetime',
            'offline_created_at' => 'datetime',
            'purchase_order_ids' => 'array',
            'sales_order_ids' => 'array',
            'purchase_requisition_ids' => 'array',
            'export_custom_fields_info' => 'array',
            'order_addresses' => 'array',
            'is_direct_sale' => 'boolean',
            'is_quotation' => 'boolean',
            'is_suspend' => 'boolean',
            'is_recurring' => 'boolean',
            'is_export' => 'boolean',
            'is_created_from_api' => 'boolean',
            'total_before_tax' => 'float',
            'tax_amount' => 'float',
            'discount_amount' => 'float',
            'shipping_charges' => 'float',
            'round_off_amount' => 'float',
            'final_total' => 'float',
            'exchange_rate' => 'float',
        ];
    }

    /* --------------------------------------------------------------------
     | Scopes
     -------------------------------------------------------------------- */

    /**
     * Unpaid documents whose payment term has elapsed.
     */
    public function scopeOverDue(Builder $query): Builder
    {
        return $query->whereIn('payment_status', [TransactionTypes::DUE, TransactionTypes::PARTIAL])
            ->whereNotNull('pay_term_number')
            ->whereNotNull('pay_term_type')
            ->whereRaw(
                "DATE_ADD(transaction_date, INTERVAL
                    CASE WHEN pay_term_type = 'months' THEN pay_term_number * 30
                         ELSE pay_term_number END DAY) < CURDATE()"
            );
    }

    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return is_array($type)
            ? $query->whereIn('transactions.type', $type)
            : $query->where('transactions.type', $type);
    }

    public function scopeForLocation(Builder $query, ?int $locationId): Builder
    {
        return empty($locationId)
            ? $query
            : $query->where('transactions.location_id', $locationId);
    }

    /**
     * Restricts to the locations the current user may access.
     */
    public function scopePermittedLocations(Builder $query): Builder
    {
        $permitted = BusinessLocation::permittedLocations();

        if ($permitted !== 'all') {
            $query->whereIn('transactions.location_id', $permitted);
        }

        return $query;
    }

    /* --------------------------------------------------------------------
     | Accessors
     -------------------------------------------------------------------- */

    public function getDocumentPathAttribute(): ?string
    {
        return empty($this->document)
            ? null
            : asset('uploads/documents/'.$this->document);
    }

    public function getDocumentNameAttribute(): ?string
    {
        if (empty($this->document)) {
            return null;
        }

        // Stored names are prefixed with a timestamp: "1712345678_invoice.pdf".
        return preg_replace('/^\d+_/', '', $this->document);
    }

    /**
     * Payment due date derived from pay_term_number + pay_term_type.
     */
    public function getDueDateAttribute(): ?\Carbon\Carbon
    {
        if (empty($this->pay_term_number) || empty($this->pay_term_type)) {
            return null;
        }

        $date = $this->transaction_date->copy();

        return $this->pay_term_type === 'months'
            ? $date->addMonths((int) $this->pay_term_number)
            : $date->addDays((int) $this->pay_term_number);
    }

    /**
     * Amount still owed on this document.
     */
    public function getDueAmountAttribute(): float
    {
        $paid = (float) $this->payment_lines()->where('is_return', 0)->sum('amount');
        $returned = (float) $this->payment_lines()->where('is_return', 1)->sum('amount');

        return round((float) $this->final_total - ($paid - $returned), 4);
    }

    /* --------------------------------------------------------------------
     | Relationships
     -------------------------------------------------------------------- */

    public function purchase_lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    public function sell_lines(): HasMany
    {
        return $this->hasMany(TransactionSellLine::class);
    }

    public function stock_adjustment_lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    public function payment_lines(): HasMany
    {
        return $this->hasMany(TransactionPayment::class, 'transaction_id');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(PaymentTerm::class, 'purchase_transaction_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_id');
    }

    public function sales_person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sale_commission_agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commission_agent');
    }

    public function delivery_person_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_person');
    }

    public function transaction_for(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expense_for');
    }

    /**
     * Who entered the document.
     *
     * Distinct from {@see transaction_for()}, which is who an expense was
     * incurred *for* — the two are different people often enough that the
     * expense screens show both.
     */
    public function created_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expense_category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function expense_sub_category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_sub_category_id');
    }

    public function price_group(): BelongsTo
    {
        return $this->belongsTo(SellingPriceGroup::class, 'selling_price_group_id');
    }

    public function types_of_service(): BelongsTo
    {
        return $this->belongsTo(TypesOfService::class, 'types_of_service_id');
    }

    public function customer_group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function preferredAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'prefer_payment_account');
    }

    /** The return document raised against this transaction. */
    public function return_parent(): HasOne
    {
        return $this->hasOne(Transaction::class, 'return_parent_id');
    }

    /** The original document this return was raised against. */
    public function return_parent_sell(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'return_parent_id');
    }

    /** The paired half of a stock transfer. */
    public function transfer_parent(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transfer_parent_id');
    }

    public function transfer_child(): HasOne
    {
        return $this->hasOne(Transaction::class, 'transfer_parent_id');
    }

    public function recurring_invoices(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recur_parent_id');
    }

    public function recurring_parent(): HasOne
    {
        return $this->hasOne(Transaction::class, 'id', 'recur_parent_id');
    }

    public function subscription_invoices(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recur_parent_id');
    }

    public function cash_register_payments(): HasMany
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'model');
    }

    /* --------------------------------------------------------------------
     | Helpers
     -------------------------------------------------------------------- */

    public function isPurchaseSide(): bool
    {
        return in_array($this->type, [
            TransactionTypes::PURCHASE,
            TransactionTypes::PURCHASE_ORDER,
            TransactionTypes::PURCHASE_REQUISITION,
            TransactionTypes::PURCHASE_RETURN,
            TransactionTypes::PURCHASE_TRANSFER,
            TransactionTypes::OPENING_STOCK,
        ], true);
    }

    public function isSellSide(): bool
    {
        return in_array($this->type, [
            TransactionTypes::SELL,
            TransactionTypes::SALES_ORDER,
            TransactionTypes::SELL_RETURN,
            TransactionTypes::SELL_TRANSFER,
        ], true);
    }

    /**
     * Whether this document still affects stock. Orders and requisitions
     * do not.
     */
    public function affectsStock(): bool
    {
        if (in_array($this->type, [
            TransactionTypes::PURCHASE_ORDER,
            TransactionTypes::PURCHASE_REQUISITION,
            TransactionTypes::SALES_ORDER,
        ], true)) {
            return false;
        }

        if ($this->type === TransactionTypes::PURCHASE) {
            return $this->status === TransactionTypes::STATUS_RECEIVED;
        }

        if ($this->type === TransactionTypes::SELL) {
            return $this->status === TransactionTypes::STATUS_FINAL;
        }

        return true;
    }

    /**
     * Editable within the tenant's transaction_edit_days window.
     */
    public function canBeEdited(): bool
    {
        $editDays = (int) (session('business.transaction_edit_days')
            ?? $this->business->transaction_edit_days
            ?? 30);

        if ($editDays === 0) {
            return true;
        }

        return $this->transaction_date->diffInDays(now()) <= $editDays;
    }

    /**
     * Properties recorded in the activity log for this document.
     *
     * @return array<string, mixed>
     */
    public function getLogPropertiesAttribute(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'invoice_no' => $this->invoice_no,
            'ref_no' => $this->ref_no,
            'final_total' => $this->final_total,
            'contact_id' => $this->contact_id,
            'location_id' => $this->location_id,
        ];
    }
}
