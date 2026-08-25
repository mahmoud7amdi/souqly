<?php

/*
 * The five account types of double-entry bookkeeping.
 *
 * This file exists because `ChartOfAccount::accountTypes()` and
 * `AccountSubtype::getAccountTypeNameAttribute()` have always called
 * `__('accounting.<type>')`, and until now there was no `accounting`
 * namespace at all — so every one of those calls returned the key itself and
 * a chart of accounts would have rendered the literal text "accounting.asset"
 * in its type column. Nothing caught it because no screen had ever rendered
 * those models.
 *
 * Kept as its own namespace rather than folded into `lang_v1` because the
 * models address it that way, and `lang_v1` already carries an `expense` key
 * meaning the document type, not the account type.
 */

return [
    'asset' => 'Asset',
    'liability' => 'Liability',
    'equity' => 'Equity',
    'income' => 'Income',
    'expense' => 'Expense',
];
