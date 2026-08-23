<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A customer, a supplier, or both.
 *
 * Extends Authenticatable because contacts can log into the customer portal
 * through the `customer` auth guard.
 */
class Contact extends Authenticatable
{
    use BelongsToBusiness, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'shipping_custom_field_details' => 'array',
            'dob' => 'date',
            'balance' => 'float',
            'credit_limit' => 'float',
            'is_default' => 'boolean',
            'is_export' => 'boolean',
        ];
    }

    /* --------------------------------------------------------------------
     | Scopes
     -------------------------------------------------------------------- */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('contact_status', 'active');
    }

    public function scopeOnlySuppliers(Builder $query): Builder
    {
        return $query->whereIn('contacts.type', ['supplier', 'both']);
    }

    public function scopeOnlyCustomers(Builder $query): Builder
    {
        return $query->whereIn('contacts.type', ['customer', 'both']);
    }

    /**
     * Applies the per-user contact restriction (users.selected_contacts).
     */
    public function scopeOnlyOwnContact(Builder $query): Builder
    {
        $user = auth()->user();

        if (! empty($user) && $user->selected_contacts) {
            $query->join(
                'user_contact_access AS uca',
                'contacts.id',
                '=',
                'uca.contact_id'
            )->where('uca.user_id', $user->id);
        }

        return $query;
    }

    /* --------------------------------------------------------------------
     | Accessors
     -------------------------------------------------------------------- */

    public function getContactAddressAttribute(): string
    {
        return implode(', ', $this->contact_address_array);
    }

    /**
     * @return array<int, string>
     */
    public function getContactAddressArrayAttribute(): array
    {
        return array_values(array_filter([
            $this->landmark,
            $this->address_line_2,
            $this->city,
            $this->state,
            $this->country,
            $this->zip_code,
        ]));
    }

    public function getFullNameAttribute(): string
    {
        $name = trim(implode(' ', array_filter([
            $this->prefix,
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));

        return $name !== '' ? $name : (string) $this->name;
    }

    /**
     * "Acme Ltd. (John Doe)" — used in the customer dropdowns.
     */
    public function getFullNameWithBusinessAttribute(): string
    {
        $name = $this->full_name;

        if (! empty($this->supplier_business_name)) {
            return $this->supplier_business_name.' ('.$name.')';
        }

        return $name;
    }

    /* --------------------------------------------------------------------
     | Relationships
     -------------------------------------------------------------------- */

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer_group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'contact_id');
    }

    public function documentsAndnote(): MorphMany
    {
        return $this->morphMany(DocumentAndNote::class, 'notable');
    }

    public function userHavingAccess(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_contact_access');
    }

    /* --------------------------------------------------------------------
     | Helpers
     -------------------------------------------------------------------- */

    /**
     * The tenant's default "Walk-In Customer".
     */
    public static function getWalkInCustomer(int $businessId): ?self
    {
        return static::withoutGlobalScope(\App\Scopes\BusinessScope::class)
            ->where('business_id', $businessId)
            ->where('is_default', 1)
            ->first();
    }

    /**
     * Customers for a select2 dropdown.
     *
     * @return array<int, string>
     */
    public static function customersForDropdown(): array
    {
        return static::onlyCustomers()
            ->active()
            ->onlyOwnContact()
            ->select('contacts.*')
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->full_name_with_business])
            ->all();
    }

    /**
     * Suppliers for a select2 dropdown.
     *
     * @return array<int, string>
     */
    public static function suppliersForDropdown(): array
    {
        return static::onlySuppliers()
            ->active()
            ->onlyOwnContact()
            ->select('contacts.*')
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->full_name_with_business])
            ->all();
    }

    /**
     * Every contact, whichever side they sit on.
     *
     * For screens that are not about selling or buying but about money moving —
     * a payments filter spans customer receipts and supplier settlements alike,
     * and splitting the picker there would force the user to know which kind of
     * document a reference number came from before they could search for it.
     *
     * @return array<int, string>
     */
    public static function allForDropdown(): array
    {
        return static::active()
            ->onlyOwnContact()
            ->select('contacts.*')
            ->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->full_name_with_business])
            ->all();
    }
}
