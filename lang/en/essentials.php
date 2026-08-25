<?php

/*
 * Enumeration labels for the HRM module.
 *
 * Five distinct value sets share this namespace because five models address it:
 * employment types (`EssentialsEmployeeDetail::employment_type_label`),
 * document types (`EssentialsEmployeeDocument`), to-do statuses and priorities
 * (`ToDo`), and reminder intervals (`Reminder`). The priorities are also read by
 * `AssetMaintenance::priorities()` in a different module, on purpose — one
 * translation of "urgent" for the whole application.
 *
 * Keys are the stored column values, not prose: `employment_type` is an
 * `enum('permanent','contract','probation','intern','part_time')` in the schema,
 * so a key that does not match the enum byte-for-byte renders as the raw key.
 * That is what this file existing fixes — the `essentials` namespace was missing
 * entirely, so every label here used to render as "essentials.permanent".
 *
 * `contract` is a deliberate single key serving both an employment type and a
 * document type: it is the same word for the same piece of paper.
 */

return [
    // employment_type
    'permanent' => 'Permanent',
    'contract' => 'Contract',
    'probation' => 'Probation',
    'intern' => 'Intern',
    'part_time' => 'Part time',

    // document_type
    'id_proof' => 'ID proof',
    'passport' => 'Passport',
    'visa' => 'Visa',
    'certificate' => 'Certificate',
    'resume' => 'Resume',
    'medical' => 'Medical',
    'other' => 'Other',

    // to-do status
    'new' => 'New',
    'in_progress' => 'In progress',
    'on_hold' => 'On hold',
    'completed' => 'Completed',
    'closed' => 'Closed',

    // priority — shared with AssetManagement
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'urgent' => 'Urgent',

    // reminder interval
    'one_time' => 'One time',
    'every_day' => 'Every day',
    'every_week' => 'Every week',
    'every_month' => 'Every month',
];
