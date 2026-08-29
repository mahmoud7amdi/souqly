<?php

/*
 * The accounting module's own namespace.
 *
 * It began as the five account types of double-entry bookkeeping, and that part
 * is still first in the file. It exists because `ChartOfAccount::accountTypes()`
 * and `AccountSubtype::getAccountTypeNameAttribute()` have always called
 * `__('accounting.<type>')`, and until this namespace was created every one of
 * those calls returned the key itself — so a chart of accounts would have
 * rendered the literal text "accounting.asset" in its type column. Nothing
 * caught it because no screen had ever rendered those models.
 *
 * Kept as its own namespace rather than folded into `lang_v1` because the models
 * address it that way, and `lang_v1` already carries an `expense` key meaning the
 * document type rather than the account type.
 *
 * Everything after the account types belongs to the screens and the refusals.
 * Generic vocabulary — name, code, date, amount, debit, credit, balance, active —
 * is deliberately *not* duplicated here: those keys already exist in `lang_v1`
 * with the same meaning, and a second copy is how two screens come to disagree
 * about what a word means. What lives here is what only accounting says.
 */

return [

    /* ---- The five account types ---- */

    'asset' => 'Asset',
    'liability' => 'Liability',
    'equity' => 'Equity',
    'income' => 'Income',
    'expense' => 'Expense',

    /* ---- Screens ---- */

    'dashboard_subtitle' => 'What the books are worth, and what this period did to them',
    'chart_of_accounts' => 'Chart of accounts',
    'chart_of_accounts_subtitle' => 'Every account the ledger can post to',
    'add_account' => 'Add account',
    'edit_account' => 'Edit account',
    'journal' => 'Journal',
    'journal_subtitle' => 'Posted documents, newest first',
    'post_journal' => 'Post journal entry',
    'transfers' => 'Fund transfers',
    'transfers_subtitle' => 'Money moved between accounts',
    'new_transfer' => 'New transfer',
    'cost_centers' => 'Cost centres',
    'cost_centers_subtitle' => 'Where spending is attributed',
    'add_cost_center' => 'Add cost centre',
    'edit_cost_center' => 'Edit cost centre',
    'trial_balance' => 'Trial balance',
    'trial_balance_subtitle' => 'Opening, movement and closing per account',

    /* ---- Dashboard ---- */

    'assets_balance' => 'Assets',
    'liabilities_balance' => 'Liabilities',
    'equity_balance' => 'Equity',
    'period_income' => 'Income this period',
    'period_expense' => 'Expenses this period',
    'period_net' => 'Net this period',
    'income_minus_expense' => 'Income less expenses',
    'accounts_in_chart' => 'Accounts in the chart',
    'documents_posted' => 'Documents posted',
    'books_balanced' => 'Debits equal credits',
    'books_unbalanced' => 'Debits do not equal credits',
    'books_unbalanced_desc' => 'A document has been written outside the posting service, or an account was edited directly in the database. The trial balance shows which side is short.',
    'recent_documents' => 'Recent documents',
    'open_journal' => 'Open the journal',
    'open_trial_balance' => 'Open the trial balance',
    'no_documents_in_period' => 'Nothing posted in this period',
    'no_documents_in_period_desc' => 'Change the dates above, or post the first entry.',

    /* ---- Chart of accounts ---- */

    'gl_code' => 'GL code',
    'gl_code_hint' => 'Optional. Unique per business, and what reports sort accounts by.',
    'account_type' => 'Account type',
    'account_type_hint' => 'Decides the account\'s natural side: assets and expenses are debit-positive, everything else is credit-positive.',
    'parent_account' => 'Parent account',
    'parent_account_hint' => 'Leave empty for a top-level account. An account cannot be placed under one of its own descendants.',
    'current_balance' => 'Current balance',
    'opening_balance_hint' => 'The balance at inception, on the account\'s natural side. Reports add every posting to it.',
    'allow_manual' => 'Allow manual entries',
    'allow_manual_hint' => 'Recorded for reference. Manual posting is controlled by the journal permission, not by this flag.',
    'account_active_hint' => 'An archived account keeps its history and still appears in the chart, but is no longer offered when posting.',
    'account_details' => 'Account details',
    'account_details_hint' => 'What the account is called, where it sits, and which side it is naturally on.',
    'account_opening' => 'Opening position',
    'account_opening_hint' => 'Only needed when the business is carrying a balance in from a previous system.',
    'total_accounts' => 'Accounts',
    'active_accounts' => 'Active',
    'manual_accounts' => 'Manual posting',
    'account_state' => 'State',
    'sub_accounts' => 'Sub-accounts',
    'account_ledger' => 'Account ledger',
    'search_accounts_placeholder' => 'Account name or GL code',
    'no_accounts_yet' => 'The chart of accounts is empty',
    'no_accounts_yet_desc' => 'Add the accounts this business posts to — cash, bank, sales, purchases — and the journal will be able to use them.',
    'no_entries_in_period' => 'No movement in this period',
    'no_entries_in_period_desc' => 'This account was not touched between the two dates above.',
    'account_is_inactive' => 'Archived',

    /* ---- Journal ---- */

    'transaction_number' => 'Document no.',
    'reference' => 'Reference',
    'document_value' => 'Value',
    'documents' => 'Documents',
    'document_lines' => 'Lines',
    'total_debit' => 'Total debit',
    'total_credit' => 'Total credit',
    'n_lines' => ':count lines',
    'document_state' => 'State',
    'state_live' => 'Live',
    'state_reversed' => 'Reversed',
    'reversed' => 'Reversed',
    'reverse' => 'Reverse',
    'reversal' => 'Reversal',
    'transfer' => 'Transfer',
    'confirm_reverse' => 'Reverse this document? A mirrored entry will be posted and both will be excluded from every balance. This cannot be undone.',
    'reversed_document_note' => 'This document has been reversed. It and its mirror are excluded from every balance — they are kept as the record of what was posted and what undid it.',
    'no_edit_note' => 'A posted document is never edited. Correct it by reversing it and posting again, so the ledger keeps both what it said and what changed it.',
    'journal_details' => 'Document details',
    'journal_details_hint' => 'The date, the reference and the note apply to the whole document.',
    'journal_lines' => 'Journal lines',
    'journal_lines_hint' => 'Every line carries an amount on exactly one side, and the two sides must add up to the same total.',
    'line_debit' => 'Debit',
    'line_credit' => 'Credit',
    'running_debit' => 'Debit so far',
    'running_credit' => 'Credit so far',
    'balance_check_note' => 'The totals are checked on save, not while you type — there is no JavaScript on this screen. If the two sides disagree the entry is refused and nothing is written.',
    'search_journal_placeholder' => 'Document no., name or reference',
    'no_documents_yet' => 'Nothing has been posted yet',
    'no_documents_yet_desc' => 'A journal entry needs at least two lines whose debits and credits agree. Add the accounts first if the chart is still empty.',
    'posted_by' => 'Posted by',
    'cost_center' => 'Cost centre',
    'view_document' => 'Open document',

    /* ---- Transfers ---- */

    'from_account' => 'From account',
    'to_account' => 'To account',
    'transferred_by' => 'Transferred by',
    'total_transferred' => 'Total transferred',
    'transfer_details' => 'Transfer details',
    'transfer_details_hint' => 'A transfer posts a real journal document: the destination is debited and the source credited.',
    'transfer_direction_hint' => 'Money leaves the source and arrives at the destination. The two accounts must differ.',
    'search_transfers_placeholder' => 'Document no.',
    'no_transfers_yet' => 'No transfers yet',
    'no_transfers_yet_desc' => 'Use a transfer to move money between two accounts — cash to bank, or between branches — and the ledger will carry both sides.',

    /* ---- Cost centres ---- */

    'cost_center_code' => 'Code',
    'cost_center_code_hint' => 'Required and unique per business. This is what reports address the cost centre by.',
    'cost_center_type' => 'Type',
    'parent_cost_center' => 'Parent cost centre',
    'manager' => 'Manager',
    'budget_amount' => 'Budget',
    'budget_period' => 'Budget period',
    'total_budget' => 'Active budget',
    'total_cost_centers' => 'Cost centres',
    'active_cost_centers' => 'Active',
    'entries' => 'Entries',
    'cost_center_details' => 'Cost centre details',
    'cost_center_details_hint' => 'What it is called, where it sits, and who answers for it.',
    'budget_details' => 'Budget',
    'budget_details_hint' => 'Recorded against the cost centre. Nothing enforces it — it is what reports compare spending to.',
    'sort_order' => 'Sort order',
    'sort_order_hint' => 'Lower numbers appear first. Leave at zero to sort by code.',
    'cost_center_active_hint' => 'An inactive cost centre keeps every line already attributed to it, but is no longer offered when posting.',
    'search_cost_centers_placeholder' => 'Name or code',
    'no_cost_centers_yet' => 'No cost centres yet',
    'no_cost_centers_yet_desc' => 'A cost centre lets a journal line say which department or project the money belongs to, so spending can be read by area rather than only by account.',
    'type_cost' => 'Cost centre',
    'type_profit' => 'Profit centre',
    'type_investment' => 'Investment centre',
    'type_support' => 'Support centre',
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'yearly' => 'Yearly',

    /* ---- Trial balance ---- */

    'opening' => 'Opening',
    'closing' => 'Closing',
    'accounts_with_movement' => 'Accounts shown',
    'signed_as_debit_note' => 'Opening and closing are stated debit-positive, so a liability, equity or income account carrying a credit balance shows as a negative figure. That convention is what makes the two columns net to zero across the whole chart.',
    'trial_balance_note' => 'The opening column is each account\'s inception balance plus every live posting made before the window, which is the only reading under which opening plus movement equals closing. Accounts with no opening balance and no movement in the window are left out; the tile above counts the whole chart.',
    'opening_not_balanced' => 'The opening column does not net to zero. Opening balances are entered per account with nothing checking them against each other, so a chart can be carried in out of balance — the period\'s own debits and credits are still checked and shown below.',
    'no_movement' => 'Nothing to show for this period',
    'no_movement_desc' => 'No account has an opening balance or any movement between the two dates above.',
    'cost_center_filter_note' => 'Filtered to one cost centre. Only lines tagged to it are counted, so the two sides need not agree.',

    /* ---- Messages ---- */

    'posted_successfully' => 'Posted as :number.',
    'reversed_successfully' => 'Reversed. The mirror was posted as :number.',
    'transferred_successfully' => 'Transfer posted.',
    'reversal_of' => 'Reversal of :number',

    /* ---- Refusals ---- */

    'parent_would_cycle' => 'That parent would put the account inside its own branch.',
    'account_has_entries' => 'This account has journal entries and cannot be deleted. Archive it instead.',
    'account_has_children' => 'This account has sub-accounts. Move or delete them first.',
    'document_not_found' => 'No such document.',
    'already_reversed' => 'This document has already been reversed.',
    'not_reversible' => 'This document is marked as not reversible.',
    'transfer_same_account' => 'A transfer needs two different accounts.',
    'transfer_needs_amount' => 'A transfer needs an amount greater than zero.',
    'line_needs_one_side' => 'A journal line carries an amount on one side only — debit or credit, not both.',
    'line_cannot_be_negative' => 'A journal line cannot carry a negative amount. Put it on the other side instead.',
    'needs_two_lines' => 'A journal entry needs at least two lines with amounts on them.',
    'unbalanced_document' => 'The entry does not balance: :debit debit against :credit credit, a difference of :difference.',
    'unknown_account' => 'That account does not belong to this business.',
    'cost_center_has_entries' => 'This cost centre is used by journal entries and cannot be deleted. Deactivate it instead.',
    'cost_center_has_children' => 'This cost centre has children. Move or delete them first.',
];
