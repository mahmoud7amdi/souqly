<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `transactions` is the single ledger for EVERY movement in the system.
 * The `type` column discriminates between: sell, purchase, sell_return,
 * purchase_return, sell_transfer, purchase_transfer, opening_stock,
 * opening_balance, expense, expense_refund, stock_adjustment,
 * purchase_order, sales_order, purchase_requisition, ledger_discount
 * and payroll.
 *
 * @see \App\Support\TransactionTypes for the canonical value list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id');

            // Offline (PWA) de-duplication keys
            $table->string('offline_temp_id', 100)->nullable()->index()
                ->comment('Temporary ID from offline client');
            $table->string('offline_invoice_no', 100)->nullable()
                ->comment('Temporary invoice number from offline');
            $table->string('offline_device_id', 100)->nullable()
                ->comment('Device ID that created the offline transaction');
            $table->timestamp('offline_created_at')->nullable()
                ->comment('When the transaction was created offline');

            $table->integer('business_id')->unsigned()->index();
            $table->integer('location_id')->unsigned()->index();

            // Discriminators
            $table->string('type', 191)->index();
            $table->string('sub_type', 20)->nullable()->index();
            $table->string('status', 191)->index();
            $table->string('sub_status', 191)->nullable()->index();
            $table->boolean('is_quotation')->default(false);
            $table->string('payment_status', 191)->nullable()->index();
            $table->enum('adjustment_type', ['normal', 'abnormal'])->nullable();

            // Parties
            $table->integer('contact_id')->unsigned()->nullable()->index();
            $table->integer('customer_group_id')->nullable()
                ->comment('used to add customer group while selling');

            // Numbering
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->string('source')->nullable();
            $table->string('subscription_no')->nullable();
            $table->string('subscription_repeat_on')->nullable();
            $table->dateTime('transaction_date')->index();

            // Amounts
            $table->decimal('total_before_tax', 22, 4)->default(0)
                ->comment('Total before the purchase/invoice tax, this includes the individual product tax');
            $table->integer('tax_id')->unsigned()->nullable();
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->enum('discount_type', ['fixed', 'percentage'])->nullable()->index();
            $table->decimal('discount_amount', 22, 4)->default(0);
            $table->integer('rp_redeemed')->default(0)->comment('rp is the short form of reward points');
            $table->decimal('rp_redeemed_amount', 22, 4)->default(0);
            $table->decimal('round_off_amount', 22, 4)->default(0)
                ->comment('Difference of rounded total and actual total');
            $table->decimal('final_total', 22, 4)->default(0);

            // Shipping
            $table->string('shipping_details')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('shipping_status')->nullable();
            $table->string('delivered_to')->nullable();
            $table->bigInteger('delivery_person')->nullable()->index();
            $table->dateTime('delivery_date')->nullable()->index();
            $table->decimal('shipping_charges', 22, 4)->default(0);
            for ($i = 1; $i <= 5; $i++) {
                $table->string('shipping_custom_field_'.$i)->nullable();
            }

            // Notes / export
            $table->text('additional_notes')->nullable();
            $table->text('staff_note')->nullable();
            $table->boolean('is_export')->default(false);
            $table->longText('export_custom_fields_info')->nullable();

            // Additional expenses charged on the document
            for ($i = 1; $i <= 4; $i++) {
                $table->string('additional_expense_key_'.$i)->nullable();
                $table->decimal('additional_expense_value_'.$i, 22, 4)->default(0);
            }

            // Expense specifics
            $table->integer('expense_category_id')->unsigned()->nullable()->index();
            $table->integer('expense_sub_category_id')->nullable();
            $table->integer('expense_for')->unsigned()->nullable();
            $table->integer('commission_agent')->nullable()->index();

            // decimal(22,4) — the documented decimal(8,3) caps at 99999.999,
            // too tight for currencies quoted in thousands per unit and for
            // the 3-decimal Gulf currencies. Matches every other money column.
            $table->decimal('exchange_rate', 22, 4)->default(1);
            $table->string('document')->nullable();
            $table->decimal('total_amount_recovered', 22, 4)->nullable()
                ->comment('Used for stock adjustment.');

            // Flags
            $table->boolean('is_direct_sale')->default(0);
            $table->boolean('is_suspend')->default(0);
            $table->boolean('is_created_from_api')->default(0);

            // Document links
            $table->integer('transfer_parent_id')->nullable()->index();
            $table->integer('return_parent_id')->nullable()->index();
            $table->integer('opening_stock_product_id')->nullable();
            $table->integer('selling_price_group_id')->nullable()->index();
            $table->text('purchase_order_ids')->nullable();
            $table->text('sales_order_ids')->nullable();
            $table->text('purchase_requisition_ids')->nullable();

            // Payment terms
            $table->integer('pay_term_number')->nullable();
            $table->enum('pay_term_type', ['days', 'months'])->nullable();
            $table->string('prefer_payment_method')->nullable();
            $table->integer('prefer_payment_account')->nullable();

            // Public invoice + recurring
            $table->string('invoice_token')->nullable();
            $table->boolean('is_recurring')->default(0);
            $table->decimal('recur_interval', 22, 4)->nullable();
            $table->enum('recur_interval_type', ['days', 'months', 'years'])->nullable();
            $table->integer('recur_repetitions')->nullable();
            $table->dateTime('recur_stopped_on')->nullable();
            $table->integer('recur_parent_id')->nullable()->index();

            $table->text('order_addresses')->nullable();
            $table->integer('rp_earned')->default(0)->comment('rp is the short form of reward points');

            // Types of service (restaurant / service businesses)
            $table->integer('types_of_service_id')->nullable()->index();
            $table->decimal('packing_charge', 22, 4)->nullable();
            $table->enum('packing_charge_type', ['fixed', 'percent'])->nullable()->index();
            for ($i = 1; $i <= 6; $i++) {
                $table->text('service_custom_field_'.$i)->nullable();
            }

            // Import tracking
            $table->integer('import_batch')->nullable();
            $table->dateTime('import_time')->nullable();

            for ($i = 1; $i <= 4; $i++) {
                $table->string('custom_field_'.$i)->nullable();
            }

            $table->integer('created_by')->unsigned()->index();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('tax_id')->references('id')->on('tax_rates')->onDelete('cascade');
            $table->foreign('expense_category_id')->references('id')
                ->on('expense_categories')->onDelete('cascade');
            $table->foreign('expense_for')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
