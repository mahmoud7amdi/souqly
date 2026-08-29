<?php

return [

    /*
     * Path to the `mysqldump` binary.
     *
     * Bare, on PATH, by default. Laragon and most XAMPP installs do not put it
     * there, so this is configurable rather than guessed at: a wrong guess
     * fails every night at 02:40 with nobody watching, which is the worst way
     * for a backup to not exist.
     */
    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),

    /*
     * Where dumps land.
     *
     * Under `storage/app/private`, never `storage/app/public` and never the
     * document root: a dump is the entire database in plain text, password
     * hashes and customer records included.
     */
    'directory' => env('BACKUP_DIRECTORY', storage_path('app/private/backups')),

    // How many dumps to keep; the nightly command prunes the rest.
    'keep' => (int) env('BACKUP_KEEP', 7),

];
