<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Essentials module: documents, to-dos, reminders, messages, knowledge base,
 * sales targets — plus the full HRM suite (departments, designations,
 * employees, leave, attendance, shifts, overtime, payroll).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->longText('essentials_settings')->nullable();
        });

        Schema::create('essentials_departments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('parent_id')->unsigned()->nullable()->index();
            $table->integer('head_user_id')->unsigned()->nullable()->index();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('essentials_departments')->onDelete('set null');
            $table->foreign('head_user_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('essentials_designations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned()->index();
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('department_id')->unsigned()->nullable()->index();
            $table->integer('grade_level')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('department_id')->references('id')
                ->on('essentials_departments')->onDelete('set null');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('essentials_department_id')->nullable()->index();
            $table->integer('essentials_designation_id')->nullable()->index();
            $table->decimal('essentials_salary', 22, 4)->nullable();
            $table->string('essentials_pay_period')->nullable();
            $table->string('essentials_pay_cycle')->nullable();
            $table->integer('location_id')->nullable()->comment('user primary work location');
        });

        Schema::create('essentials_employee_details', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->unique();
            $table->integer('business_id')->unsigned()->index();
            $table->string('employee_code')->nullable()->index();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])->nullable();
            $table->string('blood_group', 10)->nullable();
            $table->string('nationality')->nullable();
            $table->string('religion')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('personal_phone')->nullable();
            $table->text('current_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relation')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_ifsc_code')->nullable();
            $table->date('join_date')->nullable();
            $table->date('confirmation_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->enum('employment_type', [
                'permanent', 'contract', 'probation', 'intern', 'part_time',
            ])->default('permanent');
            $table->integer('work_location_id')->unsigned()->nullable()->index();
            $table->integer('reporting_to')->unsigned()->nullable()->index();
            $table->integer('department_id')->unsigned()->nullable()->index();
            $table->integer('designation_id')->unsigned()->nullable()->index();
            $table->enum('status', [
                'active', 'on_leave', 'terminated', 'resigned', 'retired',
            ])->default('active');
            $table->date('exit_date')->nullable();
            $table->text('exit_reason')->nullable();
            $table->text('notes')->nullable();
            $table->enum('pay_cycle', ['monthly', 'bi_weekly', 'weekly'])->default('monthly');
            $table->date('salary_effective_date')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('work_location_id')->references('id')
                ->on('business_locations')->onDelete('set null');
            $table->foreign('reporting_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('department_id')->references('id')
                ->on('essentials_departments')->onDelete('set null');
            $table->foreign('designation_id')->references('id')
                ->on('essentials_designations')->onDelete('set null');
        });

        Schema::create('essentials_employee_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->integer('business_id')->unsigned()->index();
            $table->string('document_type');
            $table->string('document_name');
            $table->string('document_number')->nullable();
            $table->string('file_path');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('uploaded_by')->unsigned()->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('essentials_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->integer('user_id')->index();
            $table->string('type')->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('essentials_document_shares', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('document_id')->index();
            $table->enum('value_type', ['user', 'role'])->index();
            $table->integer('value');
            $table->timestamps();
        });

        Schema::create('essentials_reminders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->integer('user_id')->index();
            $table->string('name');
            $table->date('date');
            $table->time('time');
            $table->time('end_time')->nullable();
            $table->enum('repeat', ['one_time', 'every_day', 'every_week', 'every_month'])
                ->default('one_time');
            $table->timestamps();
        });

        Schema::create('essentials_to_dos', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->integer('user_id')->index();
            $table->text('task');
            $table->date('date');
            $table->date('end_date')->nullable();
            $table->string('task_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('estimated_hours')->nullable();
            $table->string('priority')->nullable()->index();
            $table->integer('created_by')->nullable()->index();
            $table->integer('is_completed')->default(0);
            $table->timestamps();
        });

        Schema::create('essentials_todos_users', function (Blueprint $table) {
            $table->integer('todo_id')->index();
            $table->integer('user_id')->index();
        });

        Schema::create('essentials_todo_comments', function (Blueprint $table) {
            $table->increments('id');
            $table->text('comment');
            $table->integer('task_id')->index();
            $table->integer('comment_by')->index();
            $table->timestamps();
        });

        Schema::create('essentials_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->integer('user_id')->index();
            $table->text('message');
            $table->integer('location_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('essentials_leave_types', function (Blueprint $table) {
            $table->increments('id');
            $table->string('leave_type');
            $table->integer('max_leave_count')->nullable();
            $table->enum('leave_count_interval', ['month', 'year'])->nullable();
            $table->integer('business_id')->index();
            $table->timestamps();
        });

        Schema::create('essentials_leaves', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('essentials_leave_type_id')->nullable()->index();
            $table->integer('business_id')->index();
            $table->integer('user_id')->index();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('half_day')->default(false);
            $table->enum('half_day_type', ['first_half', 'second_half'])->nullable();
            $table->string('ref_no')->nullable();
            $table->enum('status', ['pending', 'approved', 'cancelled'])->default('pending');
            $table->text('reason')->nullable();
            $table->string('attachment')->nullable();
            $table->text('handover_notes')->nullable();
            $table->text('status_note')->nullable();
            $table->integer('approved_by')->unsigned()->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->integer('changed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('essentials_leave_balances', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->integer('business_id')->unsigned()->index();
            $table->integer('leave_type_id')->unsigned()->index();
            $table->year('year');
            $table->decimal('allocated', 8, 2)->default(0);
            $table->decimal('used', 8, 2)->default(0);
            $table->decimal('carried_forward', 8, 2)->default(0);
            $table->decimal('adjusted', 8, 2)->default(0);
            $table->decimal('balance', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'leave_type_id', 'year'], 'elb_user_type_year_unique');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('leave_type_id')->references('id')
                ->on('essentials_leave_types')->onDelete('cascade');
        });

        Schema::create('essentials_shifts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->enum('type', ['fixed_shift', 'flexible_shift'])->default('fixed_shift')->index();
            $table->integer('business_id')->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_allowed_auto_clockout')->default(false);
            $table->time('auto_clockout_time')->nullable();
            $table->integer('grace_period_minutes')->default(0);
            $table->integer('overtime_start_after_minutes')->default(0);
            $table->text('holidays')->nullable();
            $table->json('working_days')->nullable();
            $table->timestamps();
        });

        Schema::create('essentials_user_shifts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->integer('essentials_shift_id')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('essentials_attendances', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->integer('business_id')->index();
            $table->dateTime('clock_in_time')->nullable();
            $table->dateTime('clock_out_time')->nullable();
            $table->integer('essentials_shift_id')->nullable()->index();
            $table->string('ip_address')->nullable();
            $table->text('clock_in_note')->nullable();
            $table->text('clock_out_note')->nullable();
            $table->text('clock_in_location')->nullable();
            $table->text('clock_out_location')->nullable();
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->boolean('overtime_approved')->default(false);
            $table->integer('late_minutes')->default(0);
            $table->integer('early_leaving_minutes')->default(0);
            $table->decimal('work_hours', 8, 2)->nullable();
            $table->enum('status', [
                'present', 'absent', 'half_day', 'on_leave', 'holiday', 'weekend',
            ])->default('present');
            $table->timestamps();
        });

        Schema::create('essentials_overtime', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->integer('business_id')->unsigned()->index();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('hours', 8, 2)->default(0);
            $table->decimal('rate_multiplier', 4, 2)->default(1.5);
            $table->decimal('amount', 15, 2)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->integer('approved_by')->unsigned()->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->integer('attendance_id')->unsigned()->nullable()->index();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('attendance_id')->references('id')
                ->on('essentials_attendances')->onDelete('set null');
        });

        Schema::create('essentials_holidays', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('business_id')->index();
            $table->integer('location_id')->nullable()->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('essentials_allowances_and_deductions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->index();
            $table->string('description');
            $table->enum('type', ['allowance', 'deduction']);
            $table->decimal('amount', 22, 4)->default(0);
            $table->enum('amount_type', ['fixed', 'percent'])->default('fixed');
            $table->date('applicable_date')->nullable();
            $table->timestamps();
        });

        Schema::create('essentials_user_allowance_and_deductions', function (Blueprint $table) {
            $table->integer('user_id')->index('euad_user_id_index');
            $table->integer('allowance_deduction_id')->index('euad_allowance_deduction_id_index');
        });

        // Payroll is stored as a `payroll` typed row in `transactions`.
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('essentials_duration', 8, 2)->default(0);
            $table->string('essentials_duration_unit', 20)->nullable();
            $table->decimal('essentials_amount_per_unit_duration', 22, 4)->default(0);
            $table->text('essentials_allowances')->nullable();
            $table->text('essentials_deductions')->nullable();
        });

        Schema::create('essentials_payrolls', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->integer('business_id')->index();
            $table->string('ref_no')->nullable();
            $table->tinyInteger('month');
            $table->smallInteger('year');
            $table->decimal('duration', 8, 2)->default(0);
            $table->string('duration_unit', 20)->nullable();
            $table->decimal('amount_per_unit_duration', 22, 4)->default(0);
            $table->text('allowances')->nullable();
            $table->text('deductions')->nullable();
            $table->decimal('gross_amount', 22, 4)->default(0);
            $table->integer('created_by')->index();
            $table->timestamps();
        });

        Schema::create('essentials_payroll_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->integer('location_id')->nullable()->comment('payroll for work location');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('payment_status')->default('due');
            $table->decimal('gross_total', 22, 4)->default(0);
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('essentials_payroll_group_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('payroll_group_id');
            $table->integer('transaction_id')->index();

            $table->foreign('payroll_group_id')->references('id')
                ->on('essentials_payroll_groups')->onDelete('cascade');
        });

        Schema::create('essentials_kb', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('business_id')->index();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('status');
            $table->string('kb_type');
            $table->unsignedBigInteger('parent_id')->nullable()->index()
                ->comment('id from essentials_kb table');
            $table->string('share_with')->nullable()
                ->comment('public, private, only_with');
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('essentials_kb')->onDelete('cascade');
        });

        Schema::create('essentials_kb_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('kb_id')->index();
            $table->integer('user_id')->index();
        });

        Schema::create('essentials_user_sales_targets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->index();
            $table->decimal('target_start', 22, 4)->default(0);
            $table->decimal('target_end', 22, 4)->default(0);
            $table->decimal('commission_percent', 22, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'essentials_duration', 'essentials_duration_unit',
                'essentials_amount_per_unit_duration', 'essentials_allowances',
                'essentials_deductions',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'essentials_department_id', 'essentials_designation_id', 'essentials_salary',
                'essentials_pay_period', 'essentials_pay_cycle', 'location_id',
            ]);
        });

        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn('essentials_settings');
        });

        foreach ([
            'essentials_user_sales_targets', 'essentials_kb_users', 'essentials_kb',
            'essentials_payroll_group_transactions', 'essentials_payroll_groups',
            'essentials_payrolls', 'essentials_user_allowance_and_deductions',
            'essentials_allowances_and_deductions', 'essentials_holidays',
            'essentials_overtime', 'essentials_attendances', 'essentials_user_shifts',
            'essentials_shifts', 'essentials_leave_balances', 'essentials_leaves',
            'essentials_leave_types', 'essentials_messages', 'essentials_todo_comments',
            'essentials_todos_users', 'essentials_to_dos', 'essentials_reminders',
            'essentials_document_shares', 'essentials_documents',
            'essentials_employee_documents', 'essentials_employee_details',
            'essentials_designations', 'essentials_departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
