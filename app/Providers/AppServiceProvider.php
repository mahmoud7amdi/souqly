<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\FormattingService;
use App\Services\Gateways\PaymobGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The only gateway (see NOTES.md §5). Bound to the contract so the
        // invoice-payment flow holds no provider-specific code.
        $this->app->bind(PaymentGateway::class, PaymobGateway::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading($this->app->isLocal());

        // utf8mb4 index headroom on older MySQL; harmless on 8.x.
        Schema::defaultStringLength(191);

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Blade::withoutDoubleEncoding();
        Paginator::useTailwind();

        View::share('asset_v', config('constants.asset_version'));

        $this->registerAdminBypass();
        $this->registerBladeDirectives();
        $this->registerViewComposers();
    }

    /**
     * The tenant's Admin role carries no explicit permissions — it is granted
     * everything by this gate, exactly as the source system does.
     *
     * This MUST live in the gate rather than in the controller helpers: code
     * outside a controller calls `$user->can(...)` directly — most importantly
     * `BusinessLocation::permittedLocations()`, which feeds the
     * `permittedLocations()` query scope on every transaction lookup. With the
     * bypass only in `Controller::permit()`, an admin resolved to *zero*
     * permitted locations and every purchase, sale, transfer and report query
     * silently returned nothing.
     */
    protected function registerAdminBypass(): void
    {
        Gate::before(function ($user, string $ability) {
            // `null` (not `false`) so non-admins fall through to the normal
            // permission and policy checks.
            return $user->isAdmin() ? true : null;
        });
    }

    /**
     * Formatting directives used throughout the Blade templates.
     *
     * These mirror the source system's directive names exactly so the views
     * read the same, but they resolve through FormattingService — which also
     * normalises Arabic-Indic digits and honours the tenant's precision and
     * currency-symbol side.
     */
    protected function registerBladeDirectives(): void
    {
        $service = FormattingService::class;

        Blade::directive('num_format', fn ($expr) => "<?php echo app('$service')->numF($expr); ?>");

        Blade::directive('format_quantity', fn ($expr) => "<?php echo app('$service')->quantity($expr); ?>");

        Blade::directive('format_currency', fn ($expr) => "<?php echo app('$service')->currencyF($expr); ?>");

        Blade::directive('format_date', fn ($expr) => "<?php echo app('$service')->formatDate($expr); ?>");

        Blade::directive('format_time', fn ($expr) => "<?php echo app('$service')->formatTime($expr); ?>");

        Blade::directive('format_datetime', fn ($expr) => "<?php echo app('$service')->formatDateTime($expr); ?>");

        // Coloured status pill for a transaction's document status.
        Blade::directive('transaction_status', function ($expr) {
            return "<?php echo view('components.transaction-status', ['status' => $expr])->render(); ?>";
        });

        // Coloured status pill for paid / partial / due.
        Blade::directive('payment_status', function ($expr) {
            return "<?php echo view('components.payment-status', ['status' => $expr])->render(); ?>";
        });

        // Help tooltip, suppressed when the tenant disables tooltips.
        Blade::directive('show_tooltip', function ($expr) {
            return "<?php if (session('business.enable_tooltip', true)): ?>".
                '<span class="tooltip-trigger" data-tippy-content="<?php echo e('.$expr.'); ?>">'.
                '<svg class="size-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">'.
                '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" '.
                'd="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'.
                '</svg></span><?php endif; ?>';
        });

        // True when the active locale is right-to-left.
        Blade::if('rtl', fn () => app(FormattingService::class)->isRtl());

        // Tenant module toggle: @module('account') ... @endmodule
        Blade::if('module', function (string $module) {
            return in_array($module, (array) session('business.enabled_modules'), true);
        });
    }

    /**
     * Data every layout needs, resolved once per render.
     */
    protected function registerViewComposers(): void
    {
        View::composer('*', function ($view) {
            $view->with('enabled_modules', (array) session('business.enabled_modules'));
        });

        View::composer('layouts.*', function ($view) {
            $view->with([
                'is_pusher_enabled' => ! empty(config('broadcasting.connections.pusher.key')),
                'pwa_enabled' => (bool) config('pwa.enabled'),
                'app_title' => config('constants.app_title'),
            ]);
        });
    }
}
