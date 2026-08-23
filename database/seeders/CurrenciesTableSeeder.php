<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * World currencies, with the Egyptian Pound first (the product's primary
 * market) followed by the rest of the Arab region.
 */
class CurrenciesTableSeeder extends Seeder
{
    public function run(): void
    {
        if (Currency::count() > 0) {
            $this->command?->info('Currencies already seeded — skipping.');

            return;
        }

        $currencies = [
            // --- Primary market ----------------------------------------------
            ['Egypt', 'Egyptian Pound', 'EGP', 'ج.م'],

            // --- Arab region -------------------------------------------------
            ['Saudi Arabia', 'Saudi Riyal', 'SAR', 'ر.س'],
            ['United Arab Emirates', 'UAE Dirham', 'AED', 'د.إ'],
            ['Iraq', 'Iraqi Dinar', 'IQD', 'د.ع'],
            ['Kuwait', 'Kuwaiti Dinar', 'KWD', 'د.ك'],
            ['Qatar', 'Qatari Riyal', 'QAR', 'ر.ق'],
            ['Bahrain', 'Bahraini Dinar', 'BHD', 'د.ب'],
            ['Oman', 'Omani Rial', 'OMR', 'ر.ع'],
            ['Jordan', 'Jordanian Dinar', 'JOD', 'د.أ'],
            ['Lebanon', 'Lebanese Pound', 'LBP', 'ل.ل'],
            ['Syria', 'Syrian Pound', 'SYP', 'ل.س'],
            ['Yemen', 'Yemeni Rial', 'YER', 'ر.ي'],
            ['Libya', 'Libyan Dinar', 'LYD', 'د.ل'],
            ['Tunisia', 'Tunisian Dinar', 'TND', 'د.ت'],
            ['Algeria', 'Algerian Dinar', 'DZD', 'د.ج'],
            ['Morocco', 'Moroccan Dirham', 'MAD', 'د.م'],
            ['Sudan', 'Sudanese Pound', 'SDG', 'ج.س'],
            ['Mauritania', 'Mauritanian Ouguiya', 'MRU', 'أ.م'],
            ['Somalia', 'Somali Shilling', 'SOS', 'S'],
            ['Djibouti', 'Djiboutian Franc', 'DJF', 'ف.ج'],
            ['Palestine', 'Israeli Shekel', 'ILS', '₪'],

            // --- Major international ----------------------------------------
            ['United States', 'US Dollar', 'USD', '$'],
            ['European Union', 'Euro', 'EUR', '€'],
            ['United Kingdom', 'Pound Sterling', 'GBP', '£'],
            ['Turkey', 'Turkish Lira', 'TRY', '₺'],
            ['China', 'Chinese Yuan', 'CNY', '¥'],
            ['Japan', 'Japanese Yen', 'JPY', '¥'],
            ['India', 'Indian Rupee', 'INR', '₹'],
            ['Pakistan', 'Pakistani Rupee', 'PKR', '₨'],
            ['Switzerland', 'Swiss Franc', 'CHF', 'CHF'],
            ['Canada', 'Canadian Dollar', 'CAD', 'C$'],
            ['Australia', 'Australian Dollar', 'AUD', 'A$'],
            ['Russia', 'Russian Ruble', 'RUB', '₽'],
            ['Brazil', 'Brazilian Real', 'BRL', 'R$'],
            ['South Africa', 'South African Rand', 'ZAR', 'R'],
            ['Nigeria', 'Nigerian Naira', 'NGN', '₦'],
            ['Kenya', 'Kenyan Shilling', 'KES', 'KSh'],
            ['Indonesia', 'Indonesian Rupiah', 'IDR', 'Rp'],
            ['Malaysia', 'Malaysian Ringgit', 'MYR', 'RM'],
            ['Singapore', 'Singapore Dollar', 'SGD', 'S$'],
            ['Bangladesh', 'Bangladeshi Taka', 'BDT', '৳'],
            ['Philippines', 'Philippine Peso', 'PHP', '₱'],
            ['Thailand', 'Thai Baht', 'THB', '฿'],
            ['Vietnam', 'Vietnamese Dong', 'VND', '₫'],
            ['South Korea', 'South Korean Won', 'KRW', '₩'],
            ['Mexico', 'Mexican Peso', 'MXN', 'Mex$'],
            ['Sweden', 'Swedish Krona', 'SEK', 'kr'],
            ['Norway', 'Norwegian Krone', 'NOK', 'kr'],
            ['Denmark', 'Danish Krone', 'DKK', 'kr'],
            ['Poland', 'Polish Zloty', 'PLN', 'zł'],
            ['Romania', 'Romanian Leu', 'RON', 'lei'],
            ['Albania', 'Albanian Lek', 'ALL', 'L'],
            ['Netherlands', 'Euro', 'EUR', '€'],
            ['Laos', 'Lao Kip', 'LAK', '₭'],
            ['Afghanistan', 'Afghan Afghani', 'AFN', '؋'],
        ];

        $rows = array_map(fn ($c) => [
            'country' => $c[0],
            'currency' => $c[1],
            'code' => $c[2],
            'symbol' => $c[3],
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'created_at' => now(),
            'updated_at' => now(),
        ], $currencies);

        Currency::insert($rows);

        $this->command?->info('Currencies: '.count($rows).' inserted.');
    }
}
