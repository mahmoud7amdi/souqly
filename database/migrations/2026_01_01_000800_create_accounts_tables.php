<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('business_id')->unsigned();
            $table->string('code')->nullable();
            $table->integer('parent_id')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('account_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('parent_account_type_id')->nullable()->index();
            $table->integer('business_id')->index();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->text('account_details')->nullable();
            $table->integer('account_type_id')->nullable()->index();
            $table->enum('account_type', ['saving_current', 'capital'])->nullable();
            $table->text('note')->nullable();
            $table->integer('created_by')->index();
            $table->boolean('is_closed')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_types');
        Schema::dropIfExists('expense_categories');
    }
};
