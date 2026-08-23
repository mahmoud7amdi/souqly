<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remaining modules: Superadmin (SaaS packages/subscriptions),
 * AssetManagement, Cms, InventoryManagement (physical stock count) and
 * ProductCatalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------------------------------------------------------------
         | Superadmin
         --------------------------------------------------------------- */
        Schema::create('packages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('location_count')->default(0)
                ->comment('No. of Business Locations, 0 = infinite option.');
            $table->integer('user_count')->default(0);
            $table->integer('product_count')->default(0);
            $table->integer('invoice_count')->default(0);
            $table->enum('interval', ['days', 'months', 'years'])->default('months');
            $table->integer('interval_count')->default(1);
            $table->integer('trial_days')->default(0);
            $table->decimal('price', 22, 4)->default(0);
            $table->longText('custom_permissions')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_private')->default(0);
            $table->boolean('is_one_time')->default(0);
            $table->boolean('enable_custom_link')->default(0);
            $table->string('custom_link')->nullable();
            $table->string('custom_link_text')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->integer('package_id')->unsigned()->index();
            $table->date('start_date')->nullable();
            $table->date('trial_end_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('package_price', 22, 4)->default(0);
            $table->longText('package_details')->nullable();
            $table->integer('created_id')->unsigned()->index();
            $table->string('paid_via')->nullable();
            $table->string('payment_transaction_id')->nullable();
            $table->enum('status', ['approved', 'waiting', 'declined'])->default('waiting');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('superadmin_communicator_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->text('business_ids')->nullable();
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('superadmin_frontend_pages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('slug');
            $table->longText('content')->nullable();
            $table->boolean('is_shown')->default(1);
            $table->integer('menu_order')->nullable()->default(0);
            $table->timestamps();
        });

        /* ---------------------------------------------------------------
         | AssetManagement
         --------------------------------------------------------------- */
        Schema::table('business', function (Blueprint $table) {
            $table->text('asset_settings')->nullable();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('asset_code');
            $table->string('name');
            $table->decimal('quantity', 22, 4)->default(0);
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->integer('category_id')->unsigned()->nullable();
            $table->integer('location_id')->unsigned()->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('purchase_type')->nullable();
            $table->decimal('unit_price', 22, 4)->default(0);
            $table->decimal('depreciation', 22, 4)->nullable();
            $table->boolean('is_allocatable')->default(false);
            $table->text('description')->nullable();
            $table->integer('created_by')->unsigned();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories');
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('asset_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->integer('asset_id')->unsigned()->nullable();
            $table->string('transaction_type')->index();
            $table->string('ref_no');
            $table->integer('receiver')->unsigned()->nullable()
                ->comment('id from users table, who receives asset');
            $table->decimal('quantity', 22, 4)->default(0);
            $table->dateTime('transaction_datetime');
            $table->date('allocated_upto')->nullable();
            $table->text('reason')->nullable();
            $table->integer('parent_id')->unsigned()->nullable()
                ->comment('id from asset_transactions table');
            $table->integer('created_by')->unsigned()
                ->comment('id from users table, who allocated asset');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('receiver')->references('id')->on('users');
            $table->foreign('parent_id')->references('id')->on('asset_transactions')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('asset_warranties', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('asset_id')->index();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('additional_cost', 22, 4)->default(0);
            $table->text('additional_note')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->integer('asset_id')->index();
            $table->string('maitenance_id')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('priority')->nullable()->index();
            $table->integer('created_by')->index();
            $table->integer('assigned_to')->nullable()->index();
            $table->text('details')->nullable();
            $table->text('maintenance_note')->nullable();
            $table->timestamps();
        });

        /* ---------------------------------------------------------------
         | Cms
         --------------------------------------------------------------- */
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type')->index();
            $table->string('layout')->nullable();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('tags')->nullable();
            $table->string('feature_image')->nullable();
            $table->integer('priority')->nullable();
            $table->integer('created_by')->nullable();
            $table->boolean('is_enabled')->default(1);
            $table->timestamps();
        });

        Schema::create('cms_page_metas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('cms_page_id');
            $table->string('meta_key');
            $table->longText('meta_value')->nullable();
            $table->timestamps();

            $table->foreign('cms_page_id')->references('id')->on('cms_pages')->onDelete('cascade');
        });

        Schema::create('cms_site_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('site_key')->index();
            $table->longText('site_value')->nullable();
            $table->timestamps();
        });

        /* ---------------------------------------------------------------
         | InventoryManagement (physical stock count)
         --------------------------------------------------------------- */
        Schema::create('inventory', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('branch_id');
            $table->text('name');
            $table->timestamp('end_date')->nullable();
            $table->boolean('status')->default(false);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('business_locations');
        });

        Schema::create('inventory_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('inventory_id');
            $table->unsignedInteger('product_id');
            $table->decimal('amount_after_inventory', 22, 4)->default(0);
            $table->decimal('Amount_difference', 22, 4)->default(0);
            $table->string('inventory_type')->nullable();
            $table->string('qty_before')->nullable();
            $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('variation_id')->nullable();
            $table->timestamps();

            $table->foreign('inventory_id')->references('id')->on('inventory')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
        });

        /* ---------------------------------------------------------------
         | ProductCatalogue
         --------------------------------------------------------------- */
        Schema::create('product_catalogue_client_catalogues', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->integer('contact_id')->unsigned();
            $table->integer('location_id')->unsigned();
            $table->integer('price_group_id')->unsigned()->nullable();
            $table->string('name')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('created_by')->unsigned()->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['business_id', 'contact_id']);
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('price_group_id')->references('id')
                ->on('selling_price_groups')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn('asset_settings');
        });

        foreach ([
            'product_catalogue_client_catalogues', 'inventory_products', 'inventory',
            'cms_site_details', 'cms_page_metas', 'cms_pages',
            'asset_maintenances', 'asset_warranties', 'asset_transactions', 'assets',
            'superadmin_frontend_pages', 'superadmin_communicator_logs',
            'subscriptions', 'packages',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
