<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * "These products are at or below their alert quantity."
 *
 * Delivered on the `database` channel only, and that is a decision rather than a
 * default. The app already has an in-app notification centre reading this table
 * ({@see \App\Http\Controllers\NotificationController}, the bell in
 * `layouts/partials/notifications.blade.php`), so the alert lands somewhere a
 * user already looks. Mail was the alternative and was rejected: this
 * installation ships with `MAIL_MAILER=log`, so an emailed alert would be
 * written to a log file nobody reads while reporting itself as delivered —
 * an alerting channel that silently does not alert.
 *
 * The `data` keys are not free-form: the notification list renders
 * `data['title']` and `data['body']`, and `NotificationController::show()`
 * redirects to `data['url']`. Those three names are the contract with the UI.
 */
class LowStockAlert extends Notification
{
    /**
     * @param  Collection<int, object>  $rows  as returned by StockService::lowStock()
     */
    public function __construct(public Collection $rows) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('lang_v1.stock_alerts'),
            'body' => trans_choice('lang_v1.low_stock_alert_body', $this->rows->count(), [
                'count' => $this->rows->count(),
            ]),
            'url' => route('reports.stock'),
            'count' => $this->rows->count(),
            /*
             * A sample, not the set. `notifications.data` is a TEXT column and a
             * shop with a thousand products under their alert level would write a
             * row too large for it — losing the notification entirely at exactly
             * the moment it mattered most. The count above is the real payload;
             * the report behind `url` is the full list.
             */
            'items' => $this->rows->take(10)->map(fn (object $row) => [
                'product' => $row->product,
                'variation' => $row->variation,
                'sku' => $row->sku,
                'location' => $row->location,
                'qty_available' => (float) $row->qty_available,
                'alert_quantity' => (float) $row->alert_quantity,
            ])->values()->all(),
        ];
    }
}
