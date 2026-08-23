<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every stock-IN movement (purchase, opening stock, transfer-in)
        // creates a purchase line — this row IS the lot.
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('transaction_id')->unsigned();
            $table->integer('product_id')->unsigned();
            $table->integer('variation_id')->unsigned();
            $table->decimal('quantity', 22, 4)->default(0);
            $table->decimal('secondary_unit_quantity', 22, 4)->default(0);
            $table->decimal('pp_without_discount', 22, 4)->default(0)
                ->comment('Purchase price before inline discounts');
            $table->decimal('discount_percent', 22, 4)->default(0)
                ->comment('Inline discount percentage');
            $table->decimal('purchase_price', 22, 4)->default(0);
            $table->decimal('purchase_price_inc_tax', 22, 4)->default(0);
            $table->decimal('item_tax', 22, 4)->default(0)->comment('Tax for one quantity');
            $table->integer('tax_id')->unsigned()->nullable();

            // Document links
            $table->integer('purchase_order_line_id')->nullable()->index();
            $table->integer('purchase_requisition_line_id')->nullable()->index();

            // Consumption counters
            $table->decimal('quantity_sold', 22, 4)->default(0)
                ->comment('Quantity sold from this purchase line');
            $table->decimal('quantity_adjusted', 22, 4)->default(0)
                ->comment('Quantity adjusted in stock adjustment from this purchase line');
            $table->decimal('quantity_returned', 22, 4)->default(0);
            $table->decimal('po_quantity_purchased', 22, 4)->default(0);

            // Lot / expiry
            $table->date('mfg_date')->nullable();
            $table->date('exp_date')->nullable();
            $table->string('lot_number')->nullable()->index();
            $table->integer('sub_unit_id')->nullable()->index();

            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
            $table->foreign('tax_id')->references('id')->on('tax_rates')->onDelete('cascade');
        });

        // Every stock-OUT movement (sell, transfer-out).
        Schema::create('transaction_sell_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('transaction_id')->unsigned();
            $table->integer('product_id')->unsigned();
            $table->integer('variation_id')->unsigned();
            $table->decimal('quantity', 22, 4)->default(0);
            $table->decimal('secondary_unit_quantity', 22, 4)->default(0);
            $table->decimal('quantity_returned', 22, 4)->default(0);
            $table->decimal('unit_price_before_discount', 22, 4)->default(0);
            $table->decimal('unit_price', 22, 4)->nullable()->comment('Sell price excluding tax');
            $table->enum('line_discount_type', ['fixed', 'percentage'])->nullable()->index();
            $table->decimal('line_discount_amount', 22, 4)->default(0);
            $table->decimal('unit_price_inc_tax', 22, 4)->nullable()->comment('Sell price including tax');
            $table->decimal('item_tax', 22, 4)->default(0)->comment('Tax for one quantity');
            $table->integer('tax_id')->unsigned()->nullable();
            $table->integer('discount_id')->nullable()->index();
            $table->integer('lot_no_line_id')->nullable()->index();
            $table->text('sell_line_note')->nullable();

            // Sales-order link
            $table->integer('so_line_id')->nullable()->index();
            $table->decimal('so_quantity_invoiced', 22, 4)->default(0);

            // Combo / modifier children
            $table->integer('parent_sell_line_id')->nullable()->index();
            $table->string('children_type')->default('')->index()
                ->comment('Type of children for the parent, like modifier or combo');
            $table->integer('sub_unit_id')->nullable()->index();

            // Kitchen / station routing
            $table->dateTime('station_started_at')->nullable();
            $table->dateTime('station_completed_at')->nullable();
            $table->integer('actual_prep_time_minutes')->nullable();

            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
            $table->foreign('tax_id')->references('id')->on('tax_rates')->onDelete('cascade');
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('transaction_id')->unsigned()->index();
            $table->integer('product_id')->unsigned();
            $table->integer('variation_id')->unsigned();
            $table->decimal('quantity', 22, 4)->default(0);
            $table->decimal('secondary_unit_quantity', 22, 4)->default(0);
            $table->decimal('unit_price', 22, 4)->nullable()->comment('Last purchase unit price');
            $table->integer('purchase_line_id')->nullable();
            $table->integer('removed_purchase_line')->nullable();
            $table->integer('lot_no_line_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
        });

        /**
         * FIFO map: ties each stock-OUT line to the lot(s) it consumed.
         * This is the source of truth for cost, profit and returns.
         */
        Schema::create('transaction_sell_lines_purchase_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('sell_line_id')->unsigned()->nullable()
                ->comment('id from transaction_sell_lines');
            $table->integer('stock_adjustment_line_id')->unsigned()->nullable()
                ->comment('id from stock_adjustment_lines');
            $table->integer('purchase_line_id')->unsigned()
                ->comment('id from purchase_lines');
            $table->decimal('quantity', 22, 4)->default(0);
            $table->decimal('qty_returned', 22, 4)->default(0);
            $table->timestamps();

            $table->index('sell_line_id', 'tslpl_sell_line_id_index');
            $table->index('stock_adjustment_line_id', 'tslpl_stock_adj_line_id_index');
            $table->index('purchase_line_id', 'tslpl_purchase_line_id_index');
        });

        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('transaction_id')->unsigned()->nullable();
            $table->integer('business_id')->nullable()->index();
            $table->boolean('is_return')->default(false)
                ->comment('Used during sales to return the change');
            $table->decimal('amount', 22, 4)->default(0);
            // cash|card|cheque|bank_transfer|other|custom_pay_1..7|advance
            $table->string('method', 191)->default('cash')->index();
            $table->string('payment_type')->nullable()->index()->comment('either credit or debit');
            $table->string('transaction_no')->nullable();

            // Card details
            $table->string('card_transaction_number')->nullable();
            $table->string('card_number')->nullable();
            $table->string('card_type')->nullable();
            $table->string('card_holder_name')->nullable();
            $table->string('card_month')->nullable();
            $table->string('card_year')->nullable();
            $table->string('card_security', 5)->nullable();

            $table->string('cheque_number')->nullable();
            $table->string('bank_account_number')->nullable();

            $table->string('note')->nullable();
            $table->string('document')->nullable();
            $table->string('payment_ref_no')->nullable();
            $table->integer('account_id')->nullable()->index();
            $table->dateTime('paid_on')->nullable();
            $table->integer('created_by')->index();
            $table->boolean('is_advance')->default(0);
            $table->boolean('paid_through_link')->default(0);
            $table->string('gateway')->nullable();
            $table->integer('payment_for')->nullable()->index()->comment('stores the contact id');
            $table->integer('parent_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
        });

        // Purchase payment schedule (percentage instalments + due dates).
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('purchase_transaction_id')->unsigned()->nullable()->index();
            $table->date('due_date')->nullable();
            $table->decimal('payment_term', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('purchase_transaction_id')->references('id')
                ->on('transactions')->onDelete('cascade');
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->integer('location_id')->nullable()->index();
            $table->integer('user_id')->nullable()->unsigned();
            $table->enum('status', ['close', 'open'])->default('open');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('closing_amount', 22, 4)->default(0);
            $table->integer('total_card_slips')->default(0);
            $table->integer('total_cheques')->default(0);
            $table->text('denominations')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('cash_register_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cash_register_id')->unsigned();
            $table->decimal('amount', 22, 4)->default(0);
            $table->string('pay_method', 191)->default('cash');
            $table->enum('type', ['debit', 'credit'])->index();
            $table->enum('transaction_type', ['initial', 'sell', 'transfer', 'refund'])->index();
            $table->integer('transaction_id')->nullable()->index();
            /*
             * Added (not in the documented schema): the payment row this entry
             * mirrors. `transaction_id` cannot identify it — a split-tender sale
             * has several payments against one document, and correcting one of
             * them must move exactly one drawer entry. Nullable because the
             * opening float and drawer transfers have no payment behind them.
             */
            $table->integer('transaction_payment_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('cash_register_id')->references('id')
                ->on('cash_registers')->onDelete('cascade');
        });

        // Polymorphic cash denomination breakdown (register close / payment).
        Schema::create('cash_denominations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->decimal('amount', 22, 4)->default(0);
            $table->integer('total_count')->default(0);
            $table->morphs('model');
            $table->timestamps();
        });

        Schema::create('account_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->index();
            $table->enum('type', ['debit', 'credit'])->index();
            $table->enum('sub_type', ['opening_balance', 'fund_transfer', 'deposit'])
                ->nullable()->index();
            $table->decimal('amount', 22, 4)->default(0);
            $table->string('reff_no')->nullable();
            $table->dateTime('operation_date')->index();
            $table->integer('created_by')->index();
            $table->integer('transaction_id')->nullable()->index();
            $table->integer('transaction_payment_id')->nullable()->index();
            $table->integer('transfer_transaction_id')->nullable()->index();
            $table->text('note')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('cash_denominations');
        Schema::dropIfExists('cash_register_transactions');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('transaction_payments');
        Schema::dropIfExists('transaction_sell_lines_purchase_lines');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('transaction_sell_lines');
        Schema::dropIfExists('purchase_lines');
    }
};
