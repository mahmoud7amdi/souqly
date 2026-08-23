<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `business` table is the tenancy root: every operational row in the
 * system carries a `business_id` pointing here. It doubles as the settings
 * store for the tenant (plain columns + a number of JSON/text blobs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('currency_id')->unsigned();
            $table->date('start_date')->nullable();

            // Tax identity
            $table->string('tax_number_1', 100)->nullable();
            $table->string('tax_label_1', 10)->nullable();
            $table->string('tax_number_2', 100)->nullable();
            $table->string('tax_label_2', 10)->nullable();
            $table->string('code_label_1')->nullable();
            $table->string('code_1')->nullable();
            $table->string('code_label_2')->nullable();
            $table->string('code_2')->nullable();
            $table->integer('default_sales_tax')->unsigned()->nullable();

            // Ownership + accounting basis
            $table->decimal('default_profit_percent', 5, 2)->default(0);
            $table->integer('owner_id')->unsigned();
            $table->string('time_zone')->default('Asia/Riyadh');
            $table->tinyInteger('fy_start_month')->default(1);
            $table->enum('accounting_method', ['fifo', 'lifo', 'avco'])->default('fifo');
            $table->decimal('default_sales_discount', 5, 2)->nullable();
            $table->enum('sell_price_tax', ['includes', 'excludes'])->default('includes');
            $table->string('logo')->nullable();
            $table->string('sku_prefix')->nullable();

            // Product expiry handling
            $table->boolean('enable_product_expiry')->default(0);
            $table->enum('expiry_type', ['add_expiry', 'add_manufacturing'])->default('add_expiry');
            $table->enum('on_product_expiry', ['keep_selling', 'stop_selling', 'auto_delete'])
                ->default('keep_selling');
            $table->integer('stop_selling_before')->default(0)
                ->comment('Stop selling expired item n days before expiry');

            $table->boolean('enable_tooltip')->default(1);

            // Purchase in a different currency
            $table->boolean('purchase_in_diff_currency')->default(0)
                ->comment('Allow purchase to be in different currency then the business currency');
            $table->integer('purchase_currency_id')->unsigned()->nullable();
            $table->decimal('p_exchange_rate', 22, 4)->default(1)
                ->comment('1 Purchase currency = ? Base Currency');

            // Purchase behaviour
            $table->boolean('enable_editing_product_from_purchase')->default(1);
            $table->enum('purchase_price_update_mode', ['do_not_change', 'average', 'force_new'])
                ->default('do_not_change')
                ->comment('Mode for updating product prices on purchase');
            $table->integer('transaction_edit_days')->unsigned()->default(30);
            $table->integer('stock_expiry_alert_days')->unsigned()->default(30);

            // POS / UI settings blobs
            $table->text('keyboard_shortcuts')->nullable();
            $table->text('pos_settings')->nullable();
            $table->text('weighing_scale_setting')->nullable()
                ->comment('used to store the configuration of weighing scale');

            // Product form toggles
            $table->boolean('enable_brand')->default(true);
            $table->boolean('enable_category')->default(true);
            $table->boolean('enable_sub_category')->default(true);
            $table->boolean('enable_price_tax')->default(true);
            $table->boolean('enable_purchase_status')->nullable()->default(true);
            $table->boolean('enable_lot_number')->default(false);
            $table->integer('default_unit')->nullable();
            $table->boolean('enable_sub_units')->default(false);
            $table->boolean('enable_racks')->default(false);
            $table->boolean('enable_row')->default(false);
            $table->boolean('enable_position')->default(false);

            // Sales settings
            $table->enum('sales_cmsn_agnt', ['logged_in_user', 'user', 'cmsn_agnt'])->nullable();
            $table->boolean('item_addition_method')->default(1);
            $table->boolean('enable_inline_tax')->default(1);

            // Formatting
            $table->enum('currency_symbol_placement', ['before', 'after'])->default('before');
            $table->text('enabled_modules')->nullable();
            $table->string('date_format')->default('d/m/Y');
            $table->enum('time_format', ['12', '24'])->default('24');
            $table->tinyInteger('currency_precision')->default(2);
            $table->tinyInteger('quantity_precision')->default(2);

            $table->text('ref_no_prefixes')->nullable();
            $table->char('theme_color', 20)->nullable();
            $table->integer('created_by')->nullable();
            $table->boolean('is_active')->default(true);

            // Communication settings
            $table->text('email_settings')->nullable();
            $table->text('sms_settings')->nullable();
            $table->text('custom_labels')->nullable();
            $table->text('common_settings')->nullable();

            // Reward points ("rp") configuration
            $table->boolean('enable_rp')->default(0)->comment('rp is the short form of reward points');
            $table->string('rp_name')->nullable();
            $table->decimal('amount_for_unit_rp', 22, 4)->default(1);
            $table->decimal('min_order_total_for_rp', 22, 4)->default(1);
            $table->integer('max_rp_per_order')->nullable();
            $table->decimal('redeem_amount_per_unit_rp', 22, 4)->default(1);
            $table->decimal('min_order_total_for_redeem', 22, 4)->default(1);
            $table->integer('min_redeem_point')->nullable();
            $table->integer('max_redeem_point')->nullable();
            $table->integer('rp_expiry_period')->nullable();
            $table->enum('rp_expiry_type', ['month', 'year'])->default('year');

            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->foreign('purchase_currency_id')->references('id')->on('currencies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business');
    }
};
