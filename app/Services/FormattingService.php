<?php

namespace App\Services;

use App\Models\Business;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/**
 * Number, quantity, currency and date formatting.
 *
 * Replaces the formatting half of the original `Util` class. Every figure
 * shown to a user or written to a document goes through here so precision and
 * the currency symbol side stay consistent — including in RTL locales, where
 * numerals must still render left-to-right.
 */
class FormattingService
{
    /**
     * Parse a user-entered number back into a float ("un-format").
     *
     * Handles the tenant's thousand/decimal separators, Arabic-Indic digits
     * (٠-٩ and ۰-۹) and the RTL isolate marks browsers inject.
     */
    public function numUf(mixed $value, ?Business $business = null): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = (string) $value;

        // Strip bidi control characters that RTL inputs can smuggle in.
        $value = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value);

        $value = $this->normaliseDigits($value);

        $currency = $this->currency($business);
        $thousand = $currency['thousand_separator'] ?? ',';
        $decimal = $currency['decimal_separator'] ?? '.';

        if ($thousand !== '') {
            $value = str_replace($thousand, '', $value);
        }

        if ($decimal !== '' && $decimal !== '.') {
            $value = str_replace($decimal, '.', $value);
        }

        // Drop anything that is not a digit, sign or the decimal point.
        $value = preg_replace('/[^0-9.\-]/', '', $value);

        return (float) $value;
    }

    /**
     * Format a number for display.
     *
     * @param  bool  $addCurrency  prepend/append the currency symbol
     * @param  bool  $isQuantity  use quantity_precision instead of currency_precision
     */
    public function numF(
        mixed $value,
        bool $addCurrency = false,
        ?Business $business = null,
        bool $isQuantity = false
    ): string {
        $value = (float) ($value ?: 0);

        $currency = $this->currency($business);
        $precision = $isQuantity
            ? (int) ($currency['quantity_precision'] ?? 2)
            : (int) ($currency['currency_precision'] ?? 2);

        $formatted = number_format(
            $value,
            $precision,
            $currency['decimal_separator'] ?? '.',
            $currency['thousand_separator'] ?? ','
        );

        if (! $addCurrency) {
            return $formatted;
        }

        $symbol = $currency['symbol'] ?? '';

        return ($currency['symbol_placement'] ?? 'before') === 'after'
            ? $formatted.' '.$symbol
            : $symbol.' '.$formatted;
    }

    /**
     * Format a quantity (uses quantity_precision).
     */
    public function quantity(mixed $value, ?Business $business = null): string
    {
        return $this->numF($value, false, $business, true);
    }

    /**
     * Format a money amount with the currency symbol.
     */
    public function currencyF(mixed $value, ?Business $business = null): string
    {
        return $this->numF($value, true, $business);
    }

    /**
     * Parse a user-entered date using the tenant's date format.
     */
    public function ufDate(?string $date, bool $withTime = false): ?string
    {
        if (empty($date)) {
            return null;
        }

        $format = $this->dateFormat();

        if ($withTime) {
            $format .= $this->timeFormat() === 12 ? ' h:i A' : ' H:i';
        }

        try {
            return Carbon::createFromFormat($format, $this->normaliseDigits($date))
                ->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        } catch (\Throwable) {
            // Fall back to Carbon's own parsing for ISO-ish input.
            try {
                return Carbon::parse($date)->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * Format a date for display in the tenant's format.
     */
    public function formatDate(mixed $date, bool $withTime = false): string
    {
        if (empty($date)) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse((string) $date);

        $format = $this->dateFormat();

        if ($withTime) {
            $format .= $this->timeFormat() === 12 ? ' h:i A' : ' H:i';
        }

        return $carbon->format($format);
    }

    public function formatTime(mixed $time): string
    {
        if (empty($time)) {
            return '';
        }

        $carbon = $time instanceof Carbon ? $time : Carbon::parse((string) $time);

        return $carbon->format($this->timeFormat() === 12 ? 'h:i A' : 'H:i');
    }

    public function formatDateTime(mixed $date): string
    {
        return $this->formatDate($date, true);
    }

    /**
     * Convert an amount to words — used on cheques and formal invoices.
     */
    public function numToWord(float $amount, ?Business $business = null): string
    {
        $currency = $this->currency($business);
        $precision = (int) ($currency['currency_precision'] ?? 2);

        $whole = (int) floor(abs($amount));
        $fraction = (int) round((abs($amount) - $whole) * (10 ** $precision));

        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::SPELLOUT);

        $words = $formatter->format($whole);

        if ($fraction > 0) {
            $words .= ' '.__('lang_v1.and').' '.$formatter->format($fraction);
        }

        return trim((string) $words);
    }

    /**
     * Convert Arabic-Indic and Extended Arabic-Indic digits to ASCII.
     *
     * Without this, a figure typed on an Arabic keyboard ("١٢٣٤") parses as 0.
     */
    public function normaliseDigits(string $value): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩',
                '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
    }

    /**
     * Currency + precision settings for the active (or given) tenant.
     *
     * @return array<string, mixed>
     */
    public function currency(?Business $business = null): array
    {
        if ($business instanceof Business) {
            return [
                'symbol' => $business->currency->symbol ?? '',
                'code' => $business->currency->code ?? '',
                'thousand_separator' => $business->currency->thousand_separator ?? ',',
                'decimal_separator' => $business->currency->decimal_separator ?? '.',
                'symbol_placement' => $business->currency_symbol_placement ?? 'before',
                'currency_precision' => $business->currency_precision ?? 2,
                'quantity_precision' => $business->quantity_precision ?? 2,
            ];
        }

        // Populated by the SetSessionData middleware.
        $fromSession = session('currency');

        if (! empty($fromSession)) {
            return $fromSession;
        }

        $businessId = Tenancy::id();

        if (! empty($businessId)) {
            $loaded = Business::with('currency')->find($businessId);

            if (! empty($loaded)) {
                return $this->currency($loaded);
            }
        }

        return [
            'symbol' => '',
            'code' => '',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'symbol_placement' => 'before',
            'currency_precision' => 2,
            'quantity_precision' => 2,
        ];
    }

    public function dateFormat(): string
    {
        return session('business.date_format')
            ?? config('constants.default_date_format', 'd/m/Y');
    }

    public function timeFormat(): int
    {
        return (int) (session('business.time_format') ?? 24);
    }

    /**
     * The JS-flavoured date format (moment/flatpickr) matching dateFormat().
     */
    public function jsDateFormat(): string
    {
        return strtr($this->dateFormat(), [
            'd' => 'DD',
            'm' => 'MM',
            'Y' => 'YYYY',
            'y' => 'YY',
        ]);
    }

    /**
     * True when the active locale is written right-to-left.
     */
    public function isRtl(): bool
    {
        return in_array(app()->getLocale(), config('constants.langs_rtl', []), true);
    }

    public function direction(): string
    {
        return $this->isRtl() ? 'rtl' : 'ltr';
    }

    /**
     * Percentage of a base amount.
     */
    public function calcPercentage(float $base, float $percent): float
    {
        return round($base * $percent / 100, 4);
    }

    /**
     * What percentage $amount is of $base.
     */
    public function getPercent(float $base, float $amount): float
    {
        if ($base == 0.0) {
            return 0.0;
        }

        return round($amount / $base * 100, 4);
    }
}
