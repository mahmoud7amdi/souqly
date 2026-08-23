<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_schemes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('name');
            $table->enum('scheme_type', ['blank', 'year'])->default('blank')->index();
            $table->string('number_type', 100)->default('sequential')->index();
            $table->string('prefix')->nullable();
            $table->integer('start_number')->nullable();
            $table->integer('invoice_count')->default(0);
            $table->integer('total_digits')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('invoice_layouts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->unsigned();
            $table->enum('design', ['classic', 'elegant'])->default('classic');
            $table->boolean('show_letter_head')->default(0);
            $table->string('letter_head')->nullable();
            $table->boolean('show_qr_code')->default(0);
            $table->text('qr_code_fields')->nullable();

            // Headings
            $table->text('header_text')->nullable();
            $table->string('invoice_no_prefix')->nullable();
            $table->string('quotation_no_prefix')->nullable();
            $table->string('invoice_heading')->nullable();
            $table->string('sub_heading_line1')->nullable();
            $table->string('sub_heading_line2')->nullable();
            $table->string('sub_heading_line3')->nullable();
            $table->string('sub_heading_line4')->nullable();
            $table->string('sub_heading_line5')->nullable();
            $table->string('invoice_heading_not_paid')->nullable();
            $table->string('invoice_heading_paid')->nullable();
            $table->string('quotation_heading')->nullable();

            // Credit note labels
            $table->string('cn_heading')->nullable()->comment('cn = credit note');
            $table->string('cn_no_label')->nullable();
            $table->string('cn_amount_label')->nullable();

            // Amount labels
            $table->string('sub_total_label')->nullable();
            $table->string('discount_label')->nullable();
            $table->string('tax_label')->nullable();
            $table->string('total_label')->nullable();
            $table->string('round_off_label')->nullable();
            $table->string('total_due_label')->nullable();
            $table->string('paid_label')->nullable();

            // Customer / client block
            $table->boolean('show_client_id')->default(0);
            $table->string('client_id_label')->nullable();
            $table->string('client_tax_label')->nullable();
            $table->string('date_label')->nullable();
            $table->string('date_time_format')->nullable();
            $table->boolean('show_time')->default(1);

            // Product table labels
            $table->string('table_product_label')->nullable();
            $table->string('table_qty_label')->nullable();
            $table->string('table_unit_price_label')->nullable();
            $table->string('table_subtotal_label')->nullable();
            $table->string('cat_code_label')->nullable();
            $table->text('table_tax_headings')->nullable();

            // Toggles
            $table->boolean('show_brand')->default(0);
            $table->boolean('show_sku')->default(1);
            $table->boolean('show_cat_code')->default(1);
            $table->boolean('show_expiry')->default(0);
            $table->boolean('show_lot')->default(0);
            $table->boolean('show_image')->default(0);
            $table->boolean('show_sale_description')->default(0);
            $table->string('sales_person_label')->nullable();
            $table->boolean('show_sales_person')->default(0);
            $table->string('commission_agent_label')->nullable();
            $table->boolean('show_commission_agent')->default(0);
            $table->boolean('show_previous_bal')->default(0);
            $table->string('prev_bal_label')->nullable();
            $table->string('change_return_label')->nullable();

            // Logo / business block
            $table->string('logo')->nullable();
            $table->boolean('show_logo')->default(0);
            $table->boolean('show_business_name')->default(0);
            $table->boolean('show_location_name')->default(1);
            $table->boolean('show_landmark')->default(1);
            $table->boolean('show_city')->default(1);
            $table->boolean('show_state')->default(1);
            $table->boolean('show_zip_code')->default(1);
            $table->boolean('show_country')->default(1);
            $table->boolean('show_mobile_number')->default(1);
            $table->boolean('show_alternate_number')->default(0);
            $table->boolean('show_email')->default(0);
            $table->boolean('show_tax_1')->default(1);
            $table->boolean('show_tax_2')->default(0);
            $table->boolean('show_barcode')->default(0);
            $table->boolean('show_payments')->default(0);
            $table->boolean('show_customer')->default(0);
            $table->string('customer_label')->nullable();
            $table->boolean('show_reward_point')->default(0);

            // Custom fields
            $table->text('product_custom_fields')->nullable();
            $table->text('contact_custom_fields')->nullable();
            $table->text('location_custom_fields')->nullable();

            $table->string('highlight_color', 10)->nullable();
            $table->text('footer_text')->nullable();
            $table->text('module_info')->nullable();
            $table->text('common_settings')->nullable();
            $table->boolean('is_default')->default(0);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('printers', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('name');
            $table->enum('connection_type', ['network', 'windows', 'linux'])->default('network');
            $table->enum('capability_profile', ['default', 'simple', 'SP2000', 'TEP-200M', 'P822D'])
                ->default('default');
            $table->string('char_per_line')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('port')->nullable();
            $table->string('path')->nullable();
            $table->integer('created_by')->unsigned();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('selling_price_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('business_id')->unsigned();
            $table->boolean('is_active')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned()->index();
            $table->string('location_id')->nullable();
            $table->string('name', 256);
            $table->text('landmark')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('zip_code', 7)->nullable();

            $table->integer('invoice_scheme_id')->unsigned();
            $table->integer('sale_invoice_scheme_id')->nullable();
            $table->integer('invoice_layout_id')->unsigned();
            $table->integer('sale_invoice_layout_id')->nullable()->index();
            $table->integer('selling_price_group_id')->nullable()->index();

            $table->boolean('print_receipt_on_invoice')->nullable()->default(1);
            $table->enum('receipt_printer_type', ['browser', 'printer'])->default('browser')->index();
            $table->integer('printer_id')->nullable()->index();

            $table->string('mobile')->nullable();
            $table->string('alternate_number')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_active')->default(1);
            $table->text('default_payment_accounts')->nullable();
            $table->text('featured_products')->nullable();

            $table->string('custom_field1')->nullable();
            $table->string('custom_field2')->nullable();
            $table->string('custom_field3')->nullable();
            $table->string('custom_field4')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('invoice_scheme_id')->references('id')->on('invoice_schemes')->onDelete('cascade');
            $table->foreign('invoice_layout_id')->references('id')->on('invoice_layouts')->onDelete('cascade');
        });

        Schema::create('barcodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->float('width', 22, 4)->nullable();
            $table->float('height', 22, 4)->nullable();
            $table->float('paper_width', 22, 4)->nullable();
            $table->float('paper_height', 22, 4)->nullable();
            $table->float('top_margin', 22, 4)->nullable();
            $table->float('left_margin', 22, 4)->nullable();
            $table->float('row_distance', 22, 4)->nullable();
            $table->float('col_distance', 22, 4)->nullable();
            $table->integer('stickers_in_one_row')->nullable();
            $table->boolean('is_default')->default(0);
            $table->boolean('is_continuous')->default(0);
            $table->integer('stickers_in_one_sheet')->nullable();
            $table->integer('business_id')->unsigned()->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('reference_counts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ref_type');
            $table->integer('ref_count')->default(0);
            $table->integer('business_id')->index();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->string('template_for');
            $table->text('email_body')->nullable();
            $table->text('sms_body')->nullable();
            $table->text('whatsapp_text')->nullable();
            $table->string('subject')->nullable();
            $table->string('cc')->nullable();
            $table->string('bcc')->nullable();
            $table->boolean('auto_send')->default(0);
            $table->boolean('auto_send_sms')->default(0);
            $table->boolean('auto_send_wa_notif')->default(0);
            $table->timestamps();
        });

        Schema::create('dashboard_configurations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->integer('created_by');
            $table->string('name');
            $table->string('color')->nullable();
            $table->text('configuration')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('types_of_services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('business_id')->index();
            $table->text('location_price_group')->nullable();
            $table->decimal('packing_charge', 22, 4)->nullable();
            $table->enum('packing_charge_type', ['fixed', 'percent'])->nullable();
            $table->boolean('enable_custom_fields')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('types_of_services');
        Schema::dropIfExists('dashboard_configurations');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('reference_counts');
        Schema::dropIfExists('barcodes');
        Schema::dropIfExists('business_locations');
        Schema::dropIfExists('selling_price_groups');
        Schema::dropIfExists('printers');
        Schema::dropIfExists('invoice_layouts');
        Schema::dropIfExists('invoice_schemes');
    }
};
