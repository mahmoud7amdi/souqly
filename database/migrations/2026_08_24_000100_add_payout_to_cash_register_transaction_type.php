<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Names the fifth thing a drawer can do: pay money out.
 *
 * `cash_register_transactions.transaction_type` was created with four values —
 * `initial | sell | transfer | refund` — none of which describes cash handed to
 * a supplier or spent on an expense. So those payments wrote no drawer row at
 * all, `summary()['cash_in_hand']` read high by their total, and the cashier was
 * recorded short at close for money they had correctly paid out. NOTES.md §12.1.
 *
 * A SEPARATE, ADDITIVE MIGRATION rather than an edit to the create-table
 * migration. Editing the original would only reach a database that is rebuilt
 * from scratch, and rebuilding is not an option for one that already holds real
 * shifts. `MODIFY` widens the enum in place: every existing value is carried
 * over unchanged, no row is rewritten, and the index on the column survives —
 * MySQL rebuilds it silently as part of the same statement.
 *
 * Raw SQL rather than the schema builder because Laravel cannot alter an enum's
 * value list without doctrine/dbal, which this project does not carry. Written
 * for MySQL, which is what this project targets.
 */
return new class extends Migration
{
    /**
     * The four values the column was created with, and the fifth being added.
     */
    private const ORIGINAL = ['initial', 'sell', 'transfer', 'refund'];

    private const ADDED = 'payout';

    public function up(): void
    {
        $this->setEnum([...self::ORIGINAL, self::ADDED]);
    }

    /**
     * Narrow the enum back — but only while that is lossless.
     *
     * MySQL does not refuse to drop a value that rows still use; it coerces them
     * to the empty string (or to the first value, depending on strictness), which
     * would turn a recorded payout into an unreadable row and leave every shift
     * that contains one reconciling to the wrong figure. Since a `down()` that
     * quietly corrupts money is worse than one that refuses, this one refuses and
     * says what to do about it.
     */
    public function down(): void
    {
        $payouts = DB::table('cash_register_transactions')
            ->where('transaction_type', self::ADDED)
            ->count();

        if ($payouts > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$payouts} cash register row(s) are recorded as '"
                .self::ADDED."'. Reassign or delete them before narrowing the enum, "
                .'or MySQL will blank the value and the affected shifts will no longer reconcile.'
            );
        }

        $this->setEnum(self::ORIGINAL);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function setEnum(array $values): void
    {
        $list = implode(',', array_map(fn (string $value) => "'".$value."'", $values));

        DB::statement(
            'ALTER TABLE `cash_register_transactions` '
            ."MODIFY `transaction_type` ENUM({$list}) NOT NULL"
        );
    }
};
