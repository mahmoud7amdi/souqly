<?php

/*
 * The fixed-asset register.
 *
 * Maintenance workflow statuses live here, read by `AssetMaintenance::statuses()`.
 * `AssetMaintenance::priorities()` deliberately reads from the `essentials`
 * namespace instead — low/medium/high/urgent are the same four words the to-do
 * list uses, and translating them twice is how two screens end up disagreeing
 * about what "urgent" is called.
 *
 * The vocabulary here is deliberately plain: this module is read by storekeepers
 * and technicians, not accountants. "Handed over", not "allocation date";
 * "what is wrong", not "fault description". Where a word has an accounting
 * meaning that differs from the everyday one — `current_value` is book value
 * after straight-line depreciation, `acquisition_cost` is what was paid — the
 * hint that accompanies it says so, rather than the label pretending to.
 */

return [

    /* --- Screens --- */
    'assets' => 'Assets',
    'assets_subtitle' => 'Owned equipment, what it is worth, and who is holding it',
    'asset' => 'Asset',
    'add_asset' => 'Add asset',
    'edit_asset' => 'Edit asset',
    'back_to_asset' => 'Back to asset',
    'maintenance' => 'Maintenance',
    'maintenance_subtitle' => 'Repair and service jobs raised against assets',
    'all_maintenance_jobs' => 'All jobs on this asset',
    'open_asset' => 'Open asset',
    'raise_job' => 'Raise a job',
    'edit_job' => 'Edit job',

    /* --- The asset form --- */
    'asset_details' => 'Asset details',
    'asset_details_hint' => 'What it is, where it lives, and what it cost',
    'asset_name_placeholder' => 'e.g. Delivery van, Front-counter printer',
    'asset_code' => 'Asset code',
    'asset_code_hint' => 'Leave empty and one is generated',
    'asset_location_hint' => 'The branch that holds it, not the person using it',
    'model' => 'Model',
    'serial_no' => 'Serial no.',
    'quantity_hint' => 'How many identical units this record covers',
    'quantity_floor_hint' => 'Cannot go below :allocated — that much is currently out',
    'unit_price' => 'Unit price',
    'unit_price_hint' => 'What one unit cost when it was bought',
    'is_allocatable' => 'Can be handed out',
    'is_allocatable_hint' => 'Switch off for things that never leave the branch',
    'is_allocatable_locked' => 'Cannot be switched off while units are still out',

    'acquisition' => 'Acquisition',
    'acquisition_hint' => 'How it was bought, and how it loses value',
    'purchase_date' => 'Purchase date',
    'purchase_date_hint' => 'Depreciation is counted from this date',
    'purchase_type' => 'Purchase type',
    'purchase_type_new' => 'New',
    'purchase_type_used' => 'Used',
    'purchase_type_refurbished' => 'Refurbished',
    'purchase_type_leased' => 'Leased',
    'depreciation_rate' => 'Depreciation (% per year)',
    'depreciation_rate_hint' => 'Leave at zero for things that hold their value',
    'depreciation_note' => 'Straight-line: the same amount is written off every year, and the value never falls below zero.',

    /* --- The register --- */
    'total_assets' => 'Assets',
    'acquisition_cost' => 'Acquisition cost',
    'before_depreciation' => 'Before depreciation',
    'allocated_out' => 'Handed out',
    'across_n_assets' => 'Across :count assets',
    'open_maintenance' => 'Open jobs',
    'search_assets_placeholder' => 'Name, code, model or serial…',
    'allocation_state' => 'Availability',
    'state_allocated' => 'Something is out',
    'state_available' => 'Available to hand out',
    'current_value' => 'Current value',
    'depreciating_at' => ':rate% a year',
    'n_out' => ':qty out',
    'not_allocatable' => 'Stays put',
    'fully_allocated' => 'All out',
    'partly_allocated' => 'Partly out',
    'available' => 'Available',
    'in_warranty' => 'Under warranty',
    'no_assets_yet' => 'No assets recorded yet',
    'no_assets_yet_desc' => 'Add the equipment you own to track its value and who is holding it.',

    /* --- The asset screen --- */
    'owned_quantity' => 'Owned',
    'available_quantity' => 'Available',
    'acquisition_was' => 'Cost :amount',

    /* --- Allocation --- */
    'allocate' => 'Hand over',
    'allocate_asset' => 'Hand out',
    'allocate_hint' => 'Record who is taking it and when it is due back',
    'receiver' => 'Handed to',
    'available_is' => ':qty available',
    'due_back' => 'Due back',
    'due_back_hint' => 'Leave empty if there is no return date',
    'reason' => 'Reason',
    'reason_placeholder' => 'e.g. Site visit, replacement while the other is repaired',
    'handed_over_on' => 'Handed over on',
    'defaults_to_today' => 'Defaults to now',
    'nothing_available' => 'Nothing available to hand out',
    'nothing_available_desc' => 'Every unit is already out. Take one back, or raise the quantity on the asset.',
    'allocation_history' => 'Who has it',
    'outstanding' => 'Still out',
    'return_asset' => 'Take back',
    'quantity_to_return' => 'Quantity coming back',
    'all' => 'All',
    'due' => 'Due',
    'returned' => 'Returned',
    'overdue' => 'Overdue',
    'partly_returned' => 'Partly back',
    'out' => 'Out',
    'never_allocated' => 'Never handed out',
    'never_allocated_desc' => 'Nothing has left the branch yet.',
    'allocated_successfully' => 'Handed over.',
    'returned_successfully' => 'Taken back.',

    /* --- Warranty --- */
    'warranty' => 'Warranty',
    'warranty_from' => 'Covered from',
    'warranty_to' => 'Covered until',
    'warranty_cost' => 'Extra cost',
    'warranty_cost_hint' => 'What an extended cover cost, if anything',
    'add_warranty' => 'Add cover',
    'expired' => 'Expired',
    'no_warranty' => 'No cover recorded',
    'no_warranty_desc' => 'Add the warranty dates so an expiry does not go unnoticed.',

    /* --- Maintenance --- */
    'job_details' => 'The job',
    'job_details_hint' => 'What is wrong, how urgent, and how it is going',
    'asset_fixed_hint' => 'The asset cannot be changed — a different asset means a different job',
    'job_ref_hint' => 'Leave empty and one is generated',
    'what_is_wrong' => 'What is wrong',
    'what_is_wrong_placeholder' => 'e.g. Will not power on after the storm',
    'work_note' => 'Work note',
    'work_note_hint' => 'What was done, or what is still needed',
    'work_note_placeholder' => 'e.g. Power supply replaced, waiting on a fan',
    'assignment' => 'Assignment',
    'assignment_hint' => 'Who is doing it',
    'assigned_to' => 'Assigned to',
    'assigned_to_hint' => 'Leave empty until somebody picks it up',
    'raised_by' => 'Raised by :name',
    'total_jobs' => 'All jobs',
    'search_jobs_placeholder' => 'Reference, asset or fault…',
    'no_jobs_yet' => 'No maintenance jobs',
    'no_jobs_yet_desc' => 'Raise a job when something needs repairing or servicing.',
    'no_maintenance' => 'No jobs on this asset',
    'no_maintenance_desc' => 'Nothing has been raised against it yet.',
    'open' => 'Open',
    'closed' => 'Closed',

    /* --- Maintenance statuses, read by AssetMaintenance::statuses() --- */
    'scheduled' => 'Scheduled',
    'in_progress' => 'In progress',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',

    /* --- Refusals --- *
     |
     | Every one of these says what is blocking the edit *and* what would unblock
     | it. A message that only says no sends the user to look for a bug.
     */
    'quantity_below_allocated' => 'Cannot go below :allocated — that much is currently handed out. Take some back first.',
    'cannot_disable_allocation' => 'Units are still out. Take them back before switching handing-out off, otherwise there is no way left to close the allocation.',
    'cannot_delete_allocated' => 'Units are still out. Take them back before deleting the asset.',
    'asset_not_allocatable' => 'This asset is marked as staying put, so it cannot be handed out.',
    'quantity_exceeds_available' => 'Only :available available to hand out.',
    'not_an_allocation' => 'That row is not an allocation, so there is nothing to take back.',
    'already_returned' => 'Everything on this allocation is already back.',
    'quantity_exceeds_outstanding' => 'Only :outstanding still out on this allocation.',
];
