<?php

return [

    /*
     * Set to false in tests to skip writing audit records.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * Prune records older than this many days.
     * Government systems typically require 3–7 years; start with 365 and extend via env.
     */
    'delete_records_older_than_days' => (int) env('ACTIVITY_LOGGER_RETENTION_DAYS', 365),

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    /*
     * Return the subject model even if it has been soft-deleted,
     * so audit records stay navigable after a beneficiary or claim is removed.
     */
    'subject_returns_soft_deleted_models' => true,

    'activity_model' => \Spatie\Activitylog\Models\Activity::class,

    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
