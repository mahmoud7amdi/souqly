<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('name');
            $table->decimal('amount', 22, 4)->default(0);
            $table->string('price_calculation_type')->default('percentage')->nullable()->index();
            $table->integer('selling_price_group_id')->nullable()->index();
            $table->integer('created_by')->unsigned()->index();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->string('type')->index()->comment('customer, supplier or both');
            $table->string('supplier_business_name')->nullable();
            $table->string('name');
            $table->string('prefix')->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_id')->nullable();
            $table->string('contact_status')->index()->default('active');
            $table->string('tax_number')->nullable();

            // Address
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('landmark')->nullable();
            $table->text('address_line_2')->nullable();
            $table->string('zip_code')->nullable();
            $table->date('dob')->nullable();

            // Numbers
            $table->string('mobile')->nullable();
            $table->string('landline')->nullable();
            $table->string('alternate_number')->nullable();

            // Payment terms
            $table->integer('pay_term_number')->nullable();
            $table->enum('pay_term_type', ['days', 'months'])->nullable();
            $table->decimal('credit_limit', 22, 4)->nullable();

            $table->integer('created_by')->unsigned();
            $table->decimal('balance', 22, 4)->default(0)
                ->comment('Advance balance available with the contact');

            // Reward points
            $table->integer('total_rp')->default(0)->comment('rp is the short form of reward points');
            $table->integer('total_rp_used')->default(0);
            $table->integer('total_rp_expired')->default(0);

            $table->boolean('is_default')->default(0);
            $table->text('shipping_address')->nullable();
            $table->longText('shipping_custom_field_details')->nullable();
            $table->boolean('is_export')->default(false);
            $table->string('position')->nullable();
            $table->integer('customer_group_id')->nullable()->index();

            for ($i = 1; $i <= 10; $i++) {
                $table->string('custom_field'.$i)->nullable();
            }

            for ($i = 1; $i <= 6; $i++) {
                $table->string('export_custom_field_'.$i)->nullable();
            }

            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });

        // Restricts which contacts a user may see (users.selected_contacts).
        Schema::create('user_contact_access', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->integer('contact_id')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('crm_contact_id')->references('id')->on('contacts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['crm_contact_id']);
        });

        Schema::dropIfExists('user_contact_access');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('customer_groups');
    }
};
