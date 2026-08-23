<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\User;
use App\Services\BusinessService;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Creates the development sign-in account.
 *
 * Login is by USERNAME (the whole auth stack is username-based — see
 * LoginController; `users.email` is nullable and not unique). The account owns
 * a fully provisioned tenant: Admin + Cashier roles, a location, an invoice
 * scheme and layout, the default unit, a tax rate and the walk-in customer.
 *
 * The Admin role deliberately carries no explicit permissions — `Gate::before`
 * in AppServiceProvider grants an admin every ability, which is how the source
 * system works. So this account is unrestricted.
 *
 * Idempotent: re-running leaves an existing `admin` untouched.
 *
 * The password is read from SEED_ADMIN_PASSWORD via `constants.seed_admin_password`
 * and is deliberately absent from the repository — the account is unrestricted, so
 * it is a full-control credential. See NOTES.md §12.2.
 */
class AdminUserSeeder extends Seeder
{
    public const USERNAME = 'admin';

    /**
     * Password for the seeded account, from the environment.
     *
     * Never hard-code this: a committed credential is published by the first
     * push and stays in the history and in every clone.
     *
     * @throws \RuntimeException when SEED_ADMIN_PASSWORD is not set
     */
    public static function password(): string
    {
        $password = (string) config('constants.seed_admin_password');

        if ($password === '') {
            throw new \RuntimeException(
                'AdminUserSeeder: SEED_ADMIN_PASSWORD is not set. Add it to your .env '
                .'(see .env.example) before seeding — the seeded admin is unrestricted, '
                .'so it must not fall back to a default.'
            );
        }

        return $password;
    }

    public function run(): void
    {
        if (User::where('username', self::USERNAME)->exists()) {
            $this->command?->warn(
                'AdminUserSeeder: "'.self::USERNAME.'" already exists — left unchanged.'
            );

            return;
        }

        $currency = Currency::firstOrCreate(
            ['code' => 'EGP'],
            [
                'country' => 'Egypt',
                'currency' => 'Egyptian Pound',
                'symbol' => 'ج.م',
                'thousand_separator' => ',',
                'decimal_separator' => '.',
            ]
        );

        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            [
                'name' => 'سوقلي',
                'currency_id' => $currency->id,
                'start_date' => now()->startOfYear()->toDateString(),
                'time_zone' => config('app.timezone'),
                'enabled_modules' => [
                    'purchases', 'add_sale', 'pos_sale', 'stock_transfers',
                    'stock_adjustment', 'expenses', 'account',
                    'purchase_order', 'purchase_requisition',
                ],
            ],
            [
                'first_name' => 'مدير',
                'last_name' => 'النظام',
                'username' => self::USERNAME,
                'password' => self::password(),
                'language' => 'ar',
            ]
        );

        Tenancy::forget();

        $this->command?->info(sprintf(
            'AdminUserSeeder: username=%s  password=<SEED_ADMIN_PASSWORD from .env>  (business #%d)',
            self::USERNAME,
            $business->id
        ));
    }
}
