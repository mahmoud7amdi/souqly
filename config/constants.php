<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application identity
    |--------------------------------------------------------------------------
    */
    'app_title' => env('APP_TITLE', 'Souqly ERP'),
    'asset_version' => env('ASSET_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    | Usernames listed here bypass tenancy and reach the Superadmin module.
    */
    'administrator_usernames' => array_filter(
        array_map('trim', explode(',', (string) env('ADMINISTRATOR_USERNAMES', '')))
    ),
    'allow_registration' => env('ALLOW_REGISTRATION', true),

    /*
    |--------------------------------------------------------------------------
    | Seeded admin account
    |--------------------------------------------------------------------------
    | Password for the account AdminUserSeeder provisions. Kept out of the
    | repository deliberately — the account is unrestricted (`Gate::before`
    | grants an admin every ability), so this is a full-control credential.
    | Set SEED_ADMIN_PASSWORD in .env; the seeder refuses to run without it.
    */
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Localisation
    |--------------------------------------------------------------------------
    */
    'langs' => [
        'en' => ['full_name' => 'English', 'name' => 'en'],
        'ar' => ['full_name' => 'العربية', 'name' => 'ar'],
        'fr' => ['full_name' => 'Français', 'name' => 'fr'],
        'es' => ['full_name' => 'Español', 'name' => 'es'],
        'de' => ['full_name' => 'Deutsch', 'name' => 'de'],
        'hi' => ['full_name' => 'हिन्दी', 'name' => 'hi'],
        'id' => ['full_name' => 'Bahasa Indonesia', 'name' => 'id'],
        'nl' => ['full_name' => 'Nederlands', 'name' => 'nl'],
        'pt' => ['full_name' => 'Português', 'name' => 'pt'],
        'ro' => ['full_name' => 'Română', 'name' => 'ro'],
        'sq' => ['full_name' => 'Shqip', 'name' => 'sq'],
        'tr' => ['full_name' => 'Türkçe', 'name' => 'tr'],
        'vi' => ['full_name' => 'Tiếng Việt', 'name' => 'vi'],
        'lo' => ['full_name' => 'ລາວ', 'name' => 'lo'],
        'ps' => ['full_name' => 'پښتو', 'name' => 'ps'],
        'ce' => ['full_name' => 'Нохчийн', 'name' => 'ce'],
    ],

    // Right-to-left locales — loads the RTL stylesheet and sets dir="rtl".
    'langs_rtl' => ['ar', 'ps'],

    // Locales whose glyphs need a Unicode-capable PDF font.
    'non_utf8_languages' => ['ar', 'hi', 'ps'],

    'default_date_format' => 'd/m/Y',

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'document_size_limit' => 5000000,
    'image_size_limit' => 2000000,
    'product_img_path' => 'uploads/img',
    'media_path' => 'uploads/media',
    'document_path' => 'uploads/documents',
    'business_logo_path' => 'uploads/business_logos',

    'document_upload_mimes_types' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/zip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */
    'new_notification_count_interval' => 30,
    'invoice_scheme_separator' => '',
    'mpdf_temp_path' => 'storage/app/temp',

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    */
    'enable_download_pdf' => true,
    'enable_product_bulk_edit' => true,
    'enable_convert_draft_to_invoice' => true,
    'enable_secondary_unit' => true,
    'enable_contact_assign' => true,
    'disable_purchase_in_other_currency' => false,

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    */
    'google_map_api_key' => env('GOOGLE_MAP_API_KEY'),
];
