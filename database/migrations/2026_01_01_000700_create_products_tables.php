<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->unsigned();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('variation_value_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->index();
            $table->integer('variation_template_id')->unsigned()->index();
            $table->timestamps();

            $table->foreign('variation_template_id')->references('id')
                ->on('variation_templates')->onDelete('cascade');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->index();
            $table->integer('business_id')->unsigned()->index();
            // single | variable | combo
            $table->string('type', 191)->default('single')->index();
            $table->integer('unit_id')->unsigned()->index();
            $table->integer('secondary_unit_id')->nullable()->index();
            $table->text('sub_unit_ids')->nullable();
            $table->integer('brand_id')->unsigned()->nullable();
            $table->integer('category_id')->unsigned()->nullable();
            $table->integer('sub_category_id')->unsigned()->nullable();
            $table->integer('tax')->unsigned()->nullable();
            $table->enum('tax_type', ['inclusive', 'exclusive'])->default('exclusive')->index();
            $table->boolean('enable_stock')->default(0);
            $table->decimal('alert_quantity', 22, 4)->default(0);
            $table->string('sku');
            // C39 | C128 | EAN-13 | EAN-8 | UPC-A | UPC-E | ITF-14
            $table->string('barcode_type', 191)->default('C128')->index();

            // Expiry
            $table->decimal('expiry_period', 22, 4)->nullable();
            $table->enum('expiry_period_type', ['days', 'months'])->nullable();

            $table->boolean('enable_sr_no')->default(0);
            $table->string('weight')->nullable();
            $table->string('image')->nullable();
            $table->text('product_description')->nullable();
            $table->integer('warranty_id')->nullable()->index();
            $table->integer('created_by')->unsigned()->index();
            $table->boolean('is_inactive')->default(0);
            $table->boolean('not_for_selling')->default(false);

            for ($i = 1; $i <= 20; $i++) {
                $table->string('product_custom_field'.$i)->nullable();
            }

            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('unit_id')->references('id')->on('units')->onDelete('cascade');
            $table->foreign('brand_id')->references('id')->on('brands')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('sub_category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('tax')->references('id')->on('tax_rates');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('product_variations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('variation_template_id')->nullable();
            $table->string('name')->index();
            $table->integer('product_id')->unsigned()->index();
            $table->boolean('is_dummy')->default(1);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::create('variations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->index();
            $table->integer('product_id')->unsigned();
            $table->string('sub_sku')->nullable()->index();
            $table->integer('product_variation_id')->unsigned();
            $table->integer('variation_value_id')->nullable()->index();
            $table->decimal('default_purchase_price', 22, 4)->nullable();
            $table->decimal('dpp_inc_tax', 22, 4)->default(0)
                ->comment('Default purchase price including tax');
            $table->decimal('profit_percent', 22, 4)->default(0);
            $table->decimal('default_sell_price', 22, 4)->nullable();
            $table->decimal('sell_price_inc_tax', 22, 4)->nullable()
                ->comment('Sell price including tax');
            $table->text('combo_variations')->nullable()
                ->comment('Contains the combo variation details');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('product_variation_id')->references('id')
                ->on('product_variations')->onDelete('cascade');
        });

        // Actual stock per (variation x location).
        Schema::create('variation_location_details', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id')->unsigned()->index();
            $table->integer('product_variation_id')->unsigned()->index()
                ->comment('id from product_variations table');
            $table->integer('variation_id')->unsigned()->index();
            $table->integer('location_id')->unsigned();
            $table->decimal('qty_available', 22, 4)->default(0);
            $table->timestamps();

            $table->foreign('variation_id')->references('id')->on('variations');
            $table->foreign('location_id')->references('id')->on('business_locations');
        });

        Schema::create('variation_group_prices', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('variation_id')->unsigned();
            $table->integer('price_group_id')->unsigned();
            $table->decimal('price_inc_tax', 22, 4)->default(0);
            $table->string('price_type')->default('fixed');
            $table->timestamps();

            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
            $table->foreign('price_group_id')->references('id')
                ->on('selling_price_groups')->onDelete('cascade');
        });

        // Restricts a product to specific locations.
        Schema::create('product_locations', function (Blueprint $table) {
            $table->integer('product_id')->index();
            $table->integer('location_id')->index();
        });

        Schema::create('product_racks', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned()->index();
            $table->integer('location_id')->unsigned()->index();
            $table->integer('product_id')->unsigned()->index();
            $table->string('rack')->nullable();
            $table->string('row')->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->index();
            $table->integer('brand_id')->nullable()->index();
            $table->integer('category_id')->nullable()->index();
            $table->integer('location_id')->nullable()->index();
            $table->integer('priority')->nullable()->index();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 22, 4)->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(1);
            $table->string('spg', 100)->nullable()->index()
                ->comment('Applicable in specified selling price group only');
            $table->boolean('applicable_in_spg')->default(0)->nullable();
            $table->boolean('applicable_in_cg')->default(0)->nullable();
            $table->timestamps();
        });

        Schema::create('discount_variations', function (Blueprint $table) {
            $table->integer('discount_id')->index();
            $table->integer('variation_id')->index();
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('variation_id');
            $table->decimal('old_purchase_price', 22, 4)->nullable();
            $table->decimal('new_purchase_price', 22, 4)->default(0);
            $table->decimal('old_dpp_inc_tax', 22, 4)->nullable();
            $table->decimal('new_dpp_inc_tax', 22, 4)->default(0);
            $table->decimal('old_sell_price', 22, 4)->nullable();
            $table->decimal('new_sell_price', 22, 4)->default(0);
            $table->decimal('old_sell_price_inc_tax', 22, 4)->nullable();
            $table->decimal('new_sell_price_inc_tax', 22, 4)->default(0);
            $table->decimal('old_profit_percent', 5, 2)->nullable();
            $table->decimal('new_profit_percent', 5, 2)->default(0);
            $table->enum('change_type', [
                'manual_update', 'purchase', 'bulk_update',
            ])->default('manual_update');
            $table->string('change_reason')->nullable();
            $table->text('calculation_details')->nullable();
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('created_by');
            $table->timestamps();

            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index('created_at');
            $table->index(['variation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('discount_variations');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('product_racks');
        Schema::dropIfExists('product_locations');
        Schema::dropIfExists('variation_group_prices');
        Schema::dropIfExists('variation_location_details');
        Schema::dropIfExists('variations');
        Schema::dropIfExists('product_variations');
        Schema::dropIfExists('products');
        Schema::dropIfExists('variation_value_templates');
        Schema::dropIfExists('variation_templates');
    }
};
