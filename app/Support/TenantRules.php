<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules that reproduce, on the query builder, what Eloquent would
 * have applied on its own.
 *
 * `Rule::exists()` compiles straight to the query builder. That is a fact worth
 * stating twice, because it is the root of two separate defects in this
 * repository's history (§18.8 and §12.6): a global scope is an *Eloquent*
 * feature, so `BusinessScope` never runs for an `exists` rule, and neither does
 * `SoftDeletes`. A rule reading `exists:business_locations,id` therefore accepts
 * every row in the table across every tenant, including deleted ones — while
 * looking, in the rule string, exactly like a rule that does not.
 *
 * The dropdown next to the field proves nothing about this. A dropdown is
 * scoped, but a POST does not have to come from one, and the whole purpose of a
 * validation rule is to be the thing that holds when the form is bypassed.
 *
 * So the rules live here, named, once. Fifteen call sites spelling out the same
 * three-clause chain is fifteen chances to write two of the three clauses, and
 * the missing clause is invisible at the call site — which is precisely how the
 * fourteen unscoped sites came to exist.
 */
final class TenantRules
{
    /**
     * A `business_locations.id` that genuinely belongs to the current tenant.
     *
     * `whereNull('deleted_at')` is not belt-and-braces: the table soft-deletes,
     * so without it a branch that was deleted last year still validates.
     *
     * Deliberately **not** filtered on `is_active`, though the column exists.
     * Inactive is a business state, not a tenancy invariant — a branch closed in
     * March is still ours, and gating validation on it would make an existing
     * document at that branch un-editable. `BusinessLocation::forDropdown()`
     * already keeps *new* documents away from inactive branches, which is where
     * that rule belongs.
     */
    public static function location(): Exists
    {
        return Rule::exists('business_locations', 'id')
            ->where('business_id', Tenancy::id())
            ->whereNull('deleted_at');
    }

    /**
     * A `users.id` that genuinely belongs to the current tenant.
     *
     * One clause, not two: `users` does not soft-delete, so there is no
     * `deleted_at` to exclude. Written out here anyway rather than at the call site,
     * because the reason `exists:users,id` is wrong is the same reason
     * `exists:business_locations,id` was — and the next person to reach for it
     * should find the answer in one place.
     */
    public static function user(): Exists
    {
        return Rule::exists('users', 'id')
            ->where('business_id', Tenancy::id());
    }

    /**
     * A `chart_of_accounts.id` that genuinely belongs to the current tenant.
     *
     * This one matters more than the others, and the reason is structural:
     * `journal_entries` has no `business_id` column at all, so
     * {@see \App\Modules\Accounting\Models\JournalEntry::scopeForBusiness()}
     * establishes tenancy by reaching *through* `chart_of_account_id`. The account
     * id on the way in is therefore not merely a foreign key — it is the only
     * thing that decides which tenant the posting belongs to. An unscoped
     * `exists:chart_of_accounts,id` would let a POST name a rival's account, and
     * the resulting row would then be invisible to us and visible to them: a
     * write into another tenant's ledger that our own screens could never show.
     *
     * Not filtered on `active`, for the same reason `location()` ignores
     * `is_active`: an archived account is still ours, and refusing it in
     * validation would make an existing document that touches it un-postable.
     * `ChartOfAccount::forDropdown()` already keeps new documents off inactive
     * accounts.
     */
    public static function chartOfAccount(): Exists
    {
        return Rule::exists('chart_of_accounts', 'id')
            ->where('business_id', Tenancy::id());
    }

    /**
     * A `cost_centers.id` that genuinely belongs to the current tenant.
     *
     * `cost_centers` carries `business_id` with a real foreign key, so the risk is
     * only cross-tenant reference rather than the invisible-write above — but
     * cross-tenant reference is what leaks a rival's cost-centre name into our
     * journal listing, so the clause is not optional.
     */
    public static function costCenter(): Exists
    {
        return Rule::exists('cost_centers', 'id')
            ->where('business_id', Tenancy::id());
    }
}
