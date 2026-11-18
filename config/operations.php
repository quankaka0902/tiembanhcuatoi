<?php

return [
    'monitor' => [
        'enabled' => env('BAKERY_MONITOR_ENABLED', false),
        'ca' => env('BAKERY_MONITOR_CA') ?: null,
        'web_user' => env('BAKERY_MONITOR_WEB_USER') ?: null,
        'web_password' => env('BAKERY_MONITOR_WEB_PASSWORD') ?: null,
        'alert_url' => env('BAKERY_ALERT_URL') ?: null,
        'alert_token' => env('BAKERY_ALERT_TOKEN') ?: null,
        'heartbeat_url' => env('BAKERY_HEARTBEAT_URL') ?: null,
        'heartbeat_token' => env('BAKERY_HEARTBEAT_TOKEN') ?: null,
        'worker_wait_minutes' => 5,
        'repeat_minutes' => 30,
    ],
    'backup' => [
        'enabled' => env('BAKERY_BACKUP_ENABLED', false),
        'time' => env('BAKERY_BACKUP_TIME', '03:00'),
        'max_age_hours' => (int) env('BAKERY_BACKUP_MAX_AGE_HOURS', 26),
        'root' => env('BAKERY_BACKUP_ROOT') ?: null,
        'copy_root' => env('BAKERY_BACKUP_COPY_ROOT') ?: null,
        'mysql_defaults' => env('BAKERY_BACKUP_MYSQL_DEFAULTS') ?: null,
        'mysqldump' => env('BAKERY_MYSQLDUMP', 'mysqldump'),
    ],
];
