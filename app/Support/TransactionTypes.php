<?php

namespace App\Support;

/**
 * Canonical value lists for the discriminator columns on `transactions`.
 *
 * These used to be MySQL enums; they outgrew them and became VARCHARs, so the
 * allowed values live here instead of in the schema.
 */
final class TransactionTypes
{
    /* --------------------------------------------------------------------
     | transactions.type
     -------------------------------------------------------------------- */

    public const PURCHASE = 'purchase';

    public const PURCHASE_ORDER = 'purchase_order';

    public const PURCHASE_REQUISITION = 'purchase_requisition';

    public const PURCHASE_RETURN = 'purchase_return';

    public const SELL = 'sell';

    public const SALES_ORDER = 'sales_order';

    public const SELL_RETURN = 'sell_return';

    public const SELL_TRANSFER = 'sell_transfer';

    public const PURCHASE_TRANSFER = 'purchase_transfer';

    public const OPENING_STOCK = 'opening_stock';

    public const OPENING_BALANCE = 'opening_balance';

    public const EXPENSE = 'expense';

    public const EXPENSE_REFUND = 'expense_refund';

    public const STOCK_ADJUSTMENT = 'stock_adjustment';

    public const LEDGER_DISCOUNT = 'ledger_discount';

    public const PAYROLL = 'payroll';

    /**
     * Every valid transaction type.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::PURCHASE,
            self::PURCHASE_ORDER,
            self::PURCHASE_REQUISITION,
            self::PURCHASE_RETURN,
            self::SELL,
            self::SALES_ORDER,
            self::SELL_RETURN,
            self::SELL_TRANSFER,
            self::PURCHASE_TRANSFER,
            self::OPENING_STOCK,
            self::OPENING_BALANCE,
            self::EXPENSE,
            self::EXPENSE_REFUND,
            self::STOCK_ADJUSTMENT,
            self::LEDGER_DISCOUNT,
            self::PAYROLL,
        ];
    }

    /**
     * Types that push stock INTO a location (create purchase_lines).
     *
     * @return array<int, string>
     */
    public static function stockIn(): array
    {
        return [self::PURCHASE, self::OPENING_STOCK, self::PURCHASE_TRANSFER];
    }

    /**
     * Types that pull stock OUT of a location (create transaction_sell_lines).
     *
     * @return array<int, string>
     */
    public static function stockOut(): array
    {
        return [self::SELL, self::SELL_TRANSFER];
    }

    /**
     * Types whose payments bring money IN. Every other type pays money out.
     *
     * The single answer to "which way did this money move", asked by both mirrors
     * of a payment: the bank account
     * ({@see \App\Listeners\AddAccountTransaction::direction()}) and the cash
     * drawer ({@see \App\Services\CashRegisterService::isOutgoing()}). It lives
     * here because the two disagreeing is not a cosmetic difference — it is one
     * payment appearing as a receipt in one ledger and a payment in the other.
     *
     * `sell_return` is deliberately absent: a return hands money back to the
     * customer, so it pays out even though the document sits on the sell side.
     * `purchase_return` is deliberately present, for the mirror-image reason —
     * goods go back to the supplier and the supplier's money comes to us.
     *
     * `is_return` on a payment reverses whatever this returns (change handed over
     * on a sale, an over-refund taken back), and is applied by the caller.
     *
     * @return array<int, string>
     */
    public static function moneyIn(): array
    {
        return [
            self::SELL,
            self::SALES_ORDER,
            self::PURCHASE_RETURN,
            self::EXPENSE_REFUND,
        ];
    }

    /* --------------------------------------------------------------------
     | transactions.status
     -------------------------------------------------------------------- */

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    public const STATUS_IN_TRANSIT = 'in_transit';

    /* --------------------------------------------------------------------
     | transactions.payment_status
     -------------------------------------------------------------------- */

    public const PAID = 'paid';

    public const PARTIAL = 'partial';

    public const DUE = 'due';

    /**
     * @return array<int, string>
     */
    public static function paymentStatuses(): array
    {
        return [self::PAID, self::PARTIAL, self::DUE];
    }

    /* --------------------------------------------------------------------
     | transaction_payments.method
     -------------------------------------------------------------------- */

    /**
     * @return array<string, string> method key => translation key
     */
    public static function paymentMethods(): array
    {
        return [
            'cash' => 'lang_v1.cash',
            'card' => 'lang_v1.card',
            'cheque' => 'lang_v1.cheque',
            'bank_transfer' => 'lang_v1.bank_transfer',
            'other' => 'lang_v1.other',
            'custom_pay_1' => 'lang_v1.custom_payment_1',
            'custom_pay_2' => 'lang_v1.custom_payment_2',
            'custom_pay_3' => 'lang_v1.custom_payment_3',
            'custom_pay_4' => 'lang_v1.custom_payment_4',
            'custom_pay_5' => 'lang_v1.custom_payment_5',
            'custom_pay_6' => 'lang_v1.custom_payment_6',
            'custom_pay_7' => 'lang_v1.custom_payment_7',
            'advance' => 'lang_v1.advance',
        ];
    }

    /* --------------------------------------------------------------------
     | Misc
     -------------------------------------------------------------------- */

    /**
     * @return array<string, string>
     */
    public static function shippingStatuses(): array
    {
        return [
            'ordered' => 'lang_v1.ordered',
            'packed' => 'lang_v1.packed',
            'shipped' => 'lang_v1.shipped',
            'delivered' => 'lang_v1.delivered',
            'cancelled' => 'lang_v1.cancelled',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function orderStatuses(): array
    {
        return [
            'ordered' => 'lang_v1.ordered',
            'partial' => 'lang_v1.partial',
            'completed' => 'lang_v1.completed',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function barcodeTypes(): array
    {
        return ['C128', 'C39', 'EAN-13', 'EAN-8', 'UPC-A', 'UPC-E', 'ITF-14'];
    }
}
