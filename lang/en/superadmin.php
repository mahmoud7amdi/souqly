<?php

/*
 * Billing interval units for `Package::interval_label`, which concatenates
 * `interval_count` with the label — "3 months".
 *
 * Lower-case on purpose: the accessor puts a number in front of these, and
 * "3 Months" reads as a title rather than a duration.
 *
 * The Superadmin module is deferred (NOTES §17): it is cross-tenant by nature and
 * `BusinessScope` fails closed, so its screens cannot be built without first
 * deciding how a superadmin escapes tenancy. The labels are here for the same
 * reason as `cms.php`.
 */

return [
    'days' => 'days',
    'months' => 'months',
    'years' => 'years',
];
