<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ZeeraK Application Backups
    |--------------------------------------------------------------------------
    |
    | Backups are written outside storage/app so the file archive never
    | includes previous backups recursively. Keep this directory outside
    | the public web root.
    |
    */

    'enabled' => env('BACKUP_ENABLED', false),

    'path' => env('BACKUP_PATH', storage_path('backups')),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),

    'include_database' => env('BACKUP_DATABASE', true),

    'include_files' => env('BACKUP_FILES', true),
];
