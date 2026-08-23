<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Formatting helpers
|--------------------------------------------------------------------------
|
| The Blade directives (@format_currency, @format_date, …) echo directly, which
| makes them unusable anywhere a *value* is needed rather than output — most
| often a component prop:
|
|     <x-stat :value="format_currency($total)"/>
|
| Without these, every such call site has to write
| app(FormattingService::class)->currencyF(...) inline, which puts a container
| lookup and a fully-qualified class name into markup forty times over.
|
| These are thin, deliberately: the names mirror the directives one-for-one so a
| view reads the same whether it is echoing or passing along, and all the real
| work — tenant precision, currency-symbol side, Arabic-Indic digits — stays in
| FormattingService.
|
*/

use App\Services\FormattingService;
use Illuminate\Support\HtmlString;

if (! function_exists('formatting')) {
    /**
     * The tenant-aware formatter. Resolved per call; the container caches it.
     */
    function formatting(): FormattingService
    {
        return app(FormattingService::class);
    }
}

if (! function_exists('format_currency')) {
    function format_currency(mixed $value): string
    {
        return formatting()->currencyF($value);
    }
}

if (! function_exists('format_quantity')) {
    function format_quantity(mixed $value): string
    {
        return formatting()->quantity($value);
    }
}

if (! function_exists('format_number')) {
    /**
     * Named format_number rather than num_format: the directive keeps the source
     * system's spelling for continuity, but a PHP function should read as English.
     *
     * @param  bool  $withCurrency  append/prepend the tenant's currency symbol
     */
    function format_number(mixed $value, bool $withCurrency = false): string
    {
        return formatting()->numF($value, $withCurrency);
    }
}

if (! function_exists('format_date')) {
    function format_date(mixed $date, bool $withTime = false): string
    {
        return formatting()->formatDate($date, $withTime);
    }
}

if (! function_exists('format_time')) {
    function format_time(mixed $time): string
    {
        return formatting()->formatTime($time);
    }
}

if (! function_exists('format_datetime')) {
    function format_datetime(mixed $date): string
    {
        return formatting()->formatDateTime($date);
    }
}

if (! function_exists('or_dash')) {
    /**
     * A value, or a muted em-dash when there isn't one.
     *
     * `{{ $product->brand->name ?? '—' }}` was the idiom in twenty-odd places and
     * it has two problems. The dash inherits the cell's full text colour, so a
     * column of products with no brand pulls harder on the eye than the names
     * next to it; and `??` only catches null, while an optional text field far
     * more often arrives as an empty string.
     *
     * Returns HtmlString so `{{ }}` renders the span instead of printing it —
     * the value itself still goes through e(), so this is not an escape hatch.
     * A string return type would force `{!! !!}` at every call site and put an
     * XSS footgun in twenty-odd views to save one class attribute.
     */
    function or_dash(mixed $value): HtmlString
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return new HtmlString(
            ($value === null || $value === '')
                ? '<span class="cell-none">—</span>'
                : e($value)
        );
    }
}
