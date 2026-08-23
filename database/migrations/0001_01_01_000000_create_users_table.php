<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core "users" table.
 *
 * The business foreign key is added later (see
 * add_business_foreign_keys_to_users_table) because `business.owner_id`
 * points back at `users.id` — the two tables are mutually dependent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('user_type')->default('user')->index();

            // Identity
            $table->string('surname')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('password');
            $table->char('language', 7)->default('en');

            // Tenancy + access
            $table->integer('business_id')->unsigned()->nullable();
            $table->boolean('allow_login')->default(1);
            $table->enum('status', ['active', 'inactive', 'terminated'])->default('active');
            $table->integer('crm_contact_id')->unsigned()->nullable();

            // Commission agent
            $table->boolean('is_cmmsn_agnt')->default(0);
            $table->decimal('cmmsn_percent', 4, 2)->default(0);
            $table->boolean('selected_contacts')->default(false);
            $table->decimal('max_sales_discount_percent', 5, 2)->nullable();

            // Contact details
            $table->char('contact_no', 15)->nullable();
            $table->text('address')->nullable();
            $table->char('contact_number', 20)->nullable();
            $table->string('alt_number')->nullable();
            $table->string('family_number')->nullable();

            // Personal details
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();
            $table->enum('marital_status', ['married', 'unmarried', 'divorced'])->nullable();
            $table->char('blood_group', 10)->nullable();
            $table->string('fb_link')->nullable();
            $table->string('twitter_link')->nullable();
            $table->string('social_media_1')->nullable();
            $table->string('social_media_2')->nullable();
            $table->text('permanent_address')->nullable();
            $table->text('current_address')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('custom_field_1')->nullable();
            $table->string('custom_field_2')->nullable();
            $table->string('custom_field_3')->nullable();
            $table->string('custom_field_4')->nullable();
            $table->longText('bank_details')->nullable();
            $table->string('id_proof_name')->nullable();
            $table->string('id_proof_number')->nullable();

            // Service staff (POS) availability tracking
            $table->dateTime('available_at')->nullable()
                ->comment('Service staff available time.');
            $table->dateTime('paused_at')->nullable()
                ->comment('Service staff available time paused at, Will be nulled on resume.');

            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('user_id')->unsigned()->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
