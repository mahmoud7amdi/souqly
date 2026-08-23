<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Key/value store for install version + global settings.
        Schema::create('system', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key')->index();
            $table->text('value')->nullable();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('created_by')->unsigned();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->unsigned();
            $table->string('short_code')->nullable();
            $table->integer('parent_id')->default(0)->index();
            $table->integer('created_by')->unsigned();
            $table->string('category_type')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('slug')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });

        // Polymorphic pivot allowing any model to be categorised.
        Schema::create('categorizables', function (Blueprint $table) {
            $table->integer('category_id')->index();
            $table->morphs('categorizable');
        });

        Schema::create('units', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('actual_name');
            $table->string('short_name');
            $table->boolean('allow_decimal')->default(1);
            $table->integer('base_unit_id')->nullable()->index();
            $table->decimal('base_unit_multiplier', 20, 4)->nullable();
            $table->integer('created_by')->unsigned();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('name');
            $table->enum('calculation_type', ['fixed', 'percentage'])->default('percentage');
            $table->decimal('amount', 22, 4)->default(0);
            $table->boolean('is_tax_group')->default(0);
            $table->boolean('for_tax_group')->default(0);
            $table->enum('rounding_type', ['up', 'down', 'normal'])->nullable();
            $table->integer('created_by')->unsigned();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });

        // Sub taxes belonging to a tax group.
        Schema::create('group_sub_taxes', function (Blueprint $table) {
            $table->integer('group_tax_id')->unsigned();
            $table->integer('tax_id')->unsigned();

            $table->foreign('group_tax_id')->references('id')->on('tax_rates')->onDelete('cascade');
            $table->foreign('tax_id')->references('id')->on('tax_rates')->onDelete('cascade');
        });

        Schema::table('business', function (Blueprint $table) {
            $table->foreign('default_sales_tax')->references('id')->on('tax_rates');
        });

        Schema::create('warranties', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->index();
            $table->text('description')->nullable();
            $table->integer('duration');
            $table->enum('duration_type', ['days', 'months', 'years'])->index();
            $table->timestamps();
        });

        Schema::create('sell_line_warranties', function (Blueprint $table) {
            $table->integer('sell_line_id')->index();
            $table->integer('warranty_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropForeign(['default_sales_tax']);
        });

        Schema::dropIfExists('sell_line_warranties');
        Schema::dropIfExists('warranties');
        Schema::dropIfExists('group_sub_taxes');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categorizables');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('system');
    }
};
