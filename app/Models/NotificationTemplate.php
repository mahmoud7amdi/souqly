<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'auto_send' => 'boolean',
            'auto_send_sms' => 'boolean',
            'auto_send_wa_notif' => 'boolean',
        ];
    }

    /**
     * Every notification type the system can send.
     *
     * @return array<int, string>
     */
    public static function templateTypes(): array
    {
        return [
            'new_sale', 'payment_reminder', 'payment_received', 'new_booking',
            'new_quotation', 'new_order', 'items_received', 'items_pending',
            'send_ledger', 'new_purchase_order', 'purchase_payment_paid',
            'purchase_order_status', 'new_purchase_return', 'new_sell_return',
            'stock_transfer', 'stock_adjustment',
        ];
    }
}
