<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting module: chart of accounts, journal entries, budgets,
 * cost centres, bank reconciliation, audit trail and financial statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sortname');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('payment_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->string('name');
            $table->string('system_name')->nullable();
            $table->text('description')->nullable();
            $table->tinyInteger('is_cash')->default(0);
            $table->tinyInteger('is_online')->default(0);
            $table->tinyInteger('is_system')->default(0);
            $table->tinyInteger('active')->default(1);
            $table->integer('position')->nullable();
            $table->text('options')->nullable();
            $table->string('unique_id')->nullable();
            $table->timestamps();
        });

        Schema::create('account_subtypes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->string('account_type');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('account_detail_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->unsignedBigInteger('account_subtype_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('parent_id')->nullable()->index();
            $table->integer('business_id')->index();
            $table->integer('currency_id')->default(133);
            $table->unsignedBigInteger('payment_type_id')->default(1);
            $table->unsignedBigInteger('account_subtype_id')->nullable();
            $table->unsignedBigInteger('detail_type_id')->nullable();
            $table->text('name')->nullable();
            $table->integer('gl_code')->nullable();
            $table->enum('account_type', ['asset', 'expense', 'equity', 'liability', 'income'])
                ->default('asset');
            $table->decimal('opening_balance', 22, 4)->default(0);
            $table->integer('reconcile_opening_balance')->nullable();
            $table->tinyInteger('allow_manual')->default(0);
            $table->tinyInteger('active')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by_id')->nullable();
            $table->integer('payment_type_id')->nullable();
            $table->string('transaction_type')->nullable();
            $table->integer('reference')->nullable();
            $table->string('cheque_number')->nullable();
            $table->string('receipt')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('routing_code')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->enum('type', ['profit', 'cost', 'investment', 'support'])->default('cost');
            $table->unsignedInteger('manager_id')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->decimal('budget_amount', 22, 4)->default(0);
            $table->string('budget_period')->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('cost_centers')->onDelete('cascade');
            $table->foreign('manager_id')->references('id')->on('users');
            $table->index('is_active');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('created_by_id')->nullable()->index();
            $table->string('transaction_number')->nullable()->index();
            $table->unsignedBigInteger('payment_detail_id')->nullable();
            $table->unsignedInteger('location_id')->nullable()->index();
            $table->unsignedBigInteger('currency_id')->nullable()->index();
            $table->unsignedBigInteger('chart_of_account_id')->nullable()->index();
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->string('transaction_type')->nullable();
            $table->string('transaction_sub_type')->nullable();
            $table->text('name')->nullable();
            $table->date('date')->nullable();
            $table->string('month')->nullable();
            $table->string('year')->nullable();
            $table->string('reference')->nullable();
            $table->unsignedInteger('contact_id')->nullable()->index();
            $table->decimal('debit', 22, 4)->nullable();
            $table->decimal('credit', 22, 4)->nullable();
            $table->decimal('balance', 22, 4)->nullable();
            $table->tinyInteger('active')->default(1);
            $table->tinyInteger('reversed')->default(0);
            $table->tinyInteger('reversible')->default(1);
            $table->tinyInteger('manual_entry')->default(0);
            $table->string('receipt')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('cost_center_id')->references('id')->on('cost_centers');
            $table->index('cost_center_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index();
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('journal_transaction_number');
            $table->unsignedBigInteger('transfer_from_id');
            $table->unsignedBigInteger('transfer_to_id');
            $table->integer('transfer_by_id');
            $table->decimal('amount', 22, 4)->default(0);
            $table->timestamps();

            $table->foreign('transfer_from_id')->references('id')->on('chart_of_accounts');
            $table->foreign('transfer_to_id')->references('id')->on('chart_of_accounts');
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->unsignedBigInteger('chart_of_account_id');
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->string('financial_year');
            for ($i = 1; $i <= 12; $i++) {
                $table->float('month_'.$i)->default(0);
            }
            $table->timestamps();

            $table->foreign('cost_center_id')->references('id')->on('cost_centers');
            $table->index('cost_center_id');
        });

        Schema::create('cost_center_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('cost_center_id');
            $table->unsignedBigInteger('chart_of_account_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('allocated_amount', 22, 4)->default(0);
            $table->decimal('actual_amount', 22, 4)->default(0);
            $table->decimal('variance', 22, 4)->default(0);
            $table->decimal('variance_percentage', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('cost_center_id')->references('id')->on('cost_centers')->onDelete('cascade');
            $table->foreign('chart_of_account_id')->references('id')->on('chart_of_accounts');
            $table->index(['business_id', 'period_start', 'period_end'], 'cca_business_period_index');
        });

        Schema::create('branch_capital', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('business_id')->index();
            $table->integer('location_id')->index();
            $table->integer('created_by_id');
            $table->decimal('debit', 22, 4)->nullable();
            $table->decimal('credit', 22, 4)->nullable();
            $table->text('description')->nullable();
            $table->text('date');
            $table->timestamps();
        });

        Schema::create('financial_ratios', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->date('calculation_date');
            $table->string('period_type')->default('monthly');

            foreach ([
                'current_ratio', 'quick_ratio', 'cash_ratio', 'operating_cash_flow_ratio',
                'gross_profit_margin', 'operating_profit_margin', 'net_profit_margin',
                'return_on_assets', 'return_on_equity', 'return_on_investment',
                'asset_turnover_ratio', 'inventory_turnover_ratio',
                'receivables_turnover_ratio', 'payables_turnover_ratio',
                'debt_to_equity_ratio', 'debt_to_assets_ratio', 'equity_ratio',
                'debt_service_coverage_ratio', 'interest_coverage_ratio',
                'earnings_per_share', 'price_earnings_ratio',
                'book_value_per_share', 'dividend_yield',
            ] as $ratio) {
                $table->decimal($ratio, 10, 4)->nullable();
            }

            foreach ([
                'days_sales_outstanding', 'days_inventory_outstanding',
                'days_payables_outstanding', 'cash_conversion_cycle',
            ] as $days) {
                $table->integer($days)->nullable();
            }

            foreach ([
                'total_assets', 'current_assets', 'total_liabilities', 'current_liabilities',
                'total_equity', 'total_revenue', 'gross_profit', 'operating_profit', 'net_profit',
            ] as $amount) {
                $table->decimal($amount, 20, 2)->nullable();
            }

            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->index(['business_id', 'calculation_date']);
            $table->index(['business_id', 'location_id', 'calculation_date'], 'fr_business_location_date_index');
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('chart_of_account_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->date('reconciliation_date');
            $table->date('statement_date');
            $table->string('statement_reference')->nullable();
            $table->decimal('statement_beginning_balance', 20, 2)->default(0);
            $table->decimal('statement_ending_balance', 20, 2)->default(0);
            $table->decimal('statement_deposits', 20, 2)->default(0);
            $table->decimal('statement_withdrawals', 20, 2)->default(0);
            $table->decimal('statement_fees', 20, 2)->default(0);
            $table->decimal('statement_interest', 20, 2)->default(0);
            $table->decimal('book_beginning_balance', 20, 2)->default(0);
            $table->decimal('book_ending_balance', 20, 2)->default(0);
            $table->decimal('deposits_in_transit', 20, 2)->default(0);
            $table->decimal('outstanding_checks', 20, 2)->default(0);
            $table->decimal('bank_errors', 20, 2)->default(0);
            $table->decimal('book_errors', 20, 2)->default(0);
            $table->decimal('other_adjustments', 20, 2)->default(0);
            $table->decimal('reconciled_balance', 20, 2)->default(0);
            $table->decimal('difference', 20, 2)->default(0);
            $table->enum('status', ['draft', 'in_progress', 'completed', 'approved'])->default('draft');
            $table->boolean('is_reconciled')->default(false);
            $table->unsignedInteger('prepared_by')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('chart_of_account_id')->references('id')
                ->on('chart_of_accounts')->onDelete('cascade');
            $table->index(['business_id', 'reconciliation_date']);
            $table->index(['chart_of_account_id', 'status']);
        });

        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('reconciliation_id');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->enum('type', [
                'deposit_in_transit', 'outstanding_check', 'bank_charge',
                'bank_interest', 'error_correction', 'other',
            ]);
            $table->date('transaction_date');
            $table->string('reference_number')->nullable();
            $table->string('payee')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 20, 2)->default(0);
            $table->boolean('is_cleared')->default(false);
            $table->date('cleared_date')->nullable();
            $table->timestamps();

            $table->foreign('reconciliation_id')->references('id')
                ->on('bank_reconciliations')->onDelete('cascade');
            $table->foreign('journal_entry_id')->references('id')
                ->on('journal_entries')->onDelete('set null');
            $table->index(['reconciliation_id', 'type']);
            $table->index('is_cleared');
        });

        Schema::create('accounting_audit_trail', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->string('action_type');
            $table->string('module');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('reference_number')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->boolean('requires_review')->default(false);
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['business_id', 'created_at']);
            $table->index(['model_type', 'model_id']);
            $table->index(['action_type', 'module']);
            $table->index('risk_level');
            $table->index('requires_review');
        });

        Schema::create('equity_statements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('period_type')->default('monthly');
            foreach ([
                'opening_share_capital', 'opening_retained_earnings', 'opening_reserves',
                'opening_total_equity', 'net_income', 'dividends_paid',
                'share_capital_issued', 'share_capital_repurchased',
                'other_comprehensive_income', 'transfers_to_reserves',
                'prior_period_adjustments', 'closing_share_capital',
                'closing_retained_earnings', 'closing_reserves', 'closing_total_equity',
            ] as $column) {
                $table->decimal($column, 20, 2)->default(0);
            }
            $table->text('notes')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->index(['business_id', 'period_start', 'period_end'], 'es_business_period_index');
        });

        Schema::create('variance_analysis', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedBigInteger('chart_of_account_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('variance_type');
            $table->decimal('budgeted_amount', 20, 2)->default(0);
            $table->decimal('actual_amount', 20, 2)->default(0);
            $table->decimal('previous_period_amount', 20, 2)->default(0);
            $table->decimal('forecast_amount', 20, 2)->default(0);
            $table->decimal('variance_amount', 20, 2)->default(0);
            $table->decimal('variance_percentage', 10, 2)->default(0);
            $table->enum('variance_status', ['favorable', 'unfavorable', 'neutral'])->default('neutral');
            $table->text('variance_explanation')->nullable();
            $table->text('corrective_action')->nullable();
            $table->boolean('requires_attention')->default(false);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->unsignedInteger('analyzed_by')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('chart_of_account_id')->references('id')->on('chart_of_accounts');
            $table->index(['business_id', 'period_start', 'period_end'], 'va_business_period_index');
            $table->index(['variance_type', 'severity']);
        });

        Schema::create('tax_summaries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('tax_type');
            $table->string('tax_period');
            foreach ([
                'taxable_sales', 'output_tax', 'taxable_purchases', 'input_tax',
                'net_tax_payable', 'tax_credits', 'tax_adjustments',
                'penalties_interest', 'total_tax_due', 'tax_paid', 'balance_due',
            ] as $column) {
                $table->decimal($column, 20, 2)->default(0);
            }
            $table->date('due_date')->nullable();
            $table->date('payment_date')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('return_reference')->nullable();
            $table->date('filing_date')->nullable();
            $table->enum('filing_status', ['draft', 'ready', 'filed', 'amended'])->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedInteger('prepared_by')->nullable();
            $table->unsignedInteger('filed_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->index(['business_id', 'tax_type', 'period_start'], 'ts_business_tax_period_index');
            $table->index(['filing_status', 'due_date']);
        });

        Schema::create('segment_performance', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('segment_type');
            $table->string('segment_identifier');
            $table->date('period_start');
            $table->date('period_end');
            foreach ([
                'revenue', 'cost_of_goods_sold', 'gross_profit', 'operating_expenses',
                'allocated_overhead', 'segment_profit', 'previous_period_revenue',
                'average_transaction_value',
            ] as $column) {
                $table->decimal($column, 20, 2)->default(0);
            }
            $table->decimal('gross_margin_percentage', 10, 2)->default(0);
            $table->decimal('profit_margin_percentage', 10, 2)->default(0);
            $table->decimal('return_on_investment', 10, 2)->default(0);
            $table->decimal('revenue_growth_percentage', 10, 2)->default(0);
            $table->decimal('market_share_percentage', 10, 2)->nullable();
            $table->integer('transaction_count')->default(0);
            $table->integer('customer_count')->default(0);
            $table->text('analysis_notes')->nullable();
            $table->unsignedInteger('analyzed_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->index(['business_id', 'segment_type', 'period_start'], 'sp_business_segment_index');
            $table->index(['segment_identifier', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('journal_entry_id');
        });

        foreach ([
            'segment_performance', 'tax_summaries', 'variance_analysis', 'equity_statements',
            'accounting_audit_trail', 'bank_reconciliation_lines', 'bank_reconciliations',
            'financial_ratios', 'branch_capital', 'cost_center_allocations', 'budgets',
            'transfers', 'journal_entries', 'cost_centers', 'payment_details',
            'chart_of_accounts', 'account_detail_types', 'account_subtypes',
            'payment_types', 'countries',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
