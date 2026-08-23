<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->string('file_name');
            $table->text('description')->nullable();
            $table->integer('uploaded_by')->nullable()->index();
            $table->morphs('model');
            $table->string('model_media_type')->nullable();
            $table->timestamps();
        });

        Schema::create('document_and_notes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->integer('notable_id')->index();
            $table->string('notable_type');
            $table->text('heading')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(false);
            $table->integer('created_by')->index();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // spatie/laravel-model-flags style table used to mark rows
        // (e.g. purchase requisition lines already converted to a PO).
        Schema::create('flags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->morphs('flaggable');
            $table->timestamps();

            $table->index(['name', 'flaggable_id', 'flaggable_type'], 'flags_name_flaggable_index');
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->enum('status', ['pending', 'printing', 'done', 'failed'])->default('pending');
            $table->json('payload');
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->integer('business_id')->nullable()->index();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->string('event')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('flags');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('document_and_notes');
        Schema::dropIfExists('media');
    }
};
