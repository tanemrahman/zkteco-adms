<?php

/*
|--------------------------------------------------------------------------
| ZKTeco ADMS (iClock Push Protocol)
|--------------------------------------------------------------------------
|
| Devices dial out to /iclock/* on your Laravel app. Publish this config:
|   php artisan vendor:publish --tag=zkteco-adms-config
|
*/

return [

    'route_prefix' => env('ZKTECO_ADMS_PREFIX', 'iclock'),

    'auto_register' => (bool) env('ZKTECO_ADMS_AUTO_REGISTER', true),

    'require_comm_key' => (bool) env('ZKTECO_ADMS_REQUIRE_COMM_KEY', false),
    'comm_key' => env('ZKTECO_ADMS_COMM_KEY', ''),

    'online_threshold_minutes' => (int) env('ZKTECO_ADMS_ONLINE_MINUTES', 3),

    'options' => [
        'error_delay' => (int) env('ZKTECO_ADMS_ERROR_DELAY', 30),
        'delay' => (int) env('ZKTECO_ADMS_DELAY', 10),
        'trans_times' => env('ZKTECO_ADMS_TRANS_TIMES', '00:00;14:05'),
        'trans_interval' => (int) env('ZKTECO_ADMS_TRANS_INTERVAL', 1),
        'trans_flag' => env(
            'ZKTECO_ADMS_TRANS_FLAG',
            'TransData AttLog OpLog AttPhoto EnrollUser ChgUser EnrollFP ChgFP FPImag FACE UserPic InOutFP WorkCode'
        ),
        'timezone' => (int) env('ZKTECO_ADMS_TIMEZONE', 6),
        'realtime' => (int) env('ZKTECO_ADMS_REALTIME', 1),
        'encrypt' => (int) env('ZKTECO_ADMS_ENCRYPT', 0),
        'send_server_time' => (bool) env('ZKTECO_ADMS_SEND_SERVER_TIME', true),
        'server_name' => env('ZKTECO_ADMS_SERVER_NAME', 'Laravel ADMS'),
    ],

    'responses' => [
        'ok' => 'OK',
        'data_ok' => 'OK: {count}',
        'line_ending' => "\n",
    ],

    'attendance' => [
        'source' => 'adms',
        'dedup_tolerance_seconds' => (int) env('ZKTECO_ADMS_DEDUP_TOLERANCE', 5),
        'retention_days' => (int) env('ZKTECO_ADMS_RETENTION_DAYS', 30),
        'future_skew_minutes' => (int) env('ZKTECO_ADMS_FUTURE_SKEW', 360),
        // null = use each device's timezone hour offset (TimeZone=N from handshake).
        // Set e.g. Asia/Dhaka only when you want a fixed IANA zone for all devices.
        'device_timezone' => env('ZKTECO_ADMS_DEVICE_TIMEZONE'),
        // When true, TransactionsReceived is dispatched via queue after punches save.
        'queue_processing' => (bool) env('ZKTECO_ADMS_QUEUE_PROCESSING', false),
        'queue' => env('ZKTECO_ADMS_QUEUE', 'default'),
    ],

    // Reject requests from devices with status=false (soft-disabled).
    'reject_inactive' => (bool) env('ZKTECO_ADMS_REJECT_INACTIVE', false),

    'logging' => [
        'enabled' => (bool) env('ZKTECO_ADMS_LOG_ENABLED', true),
        'log_heartbeats' => (bool) env('ZKTECO_ADMS_LOG_HEARTBEATS', false),
        'max_body' => (int) env('ZKTECO_ADMS_LOG_MAX_BODY', 10000),
        'retention_days' => (int) env('ZKTECO_ADMS_LOG_RETENTION_DAYS', 14),
        'heartbeat_retention_days' => (int) env('ZKTECO_ADMS_HEARTBEAT_RETENTION_DAYS', 3),
    ],

    'commands' => [
        'stale_after_minutes' => (int) env('ZKTECO_ADMS_CMD_STALE_MINUTES', 30),
        'max_per_poll' => (int) env('ZKTECO_ADMS_CMD_MAX_PER_POLL', 10),
    ],

    'attphoto' => [
        'enabled' => (bool) env('ZKTECO_ADMS_ATTPHOTO', true),
        'disk' => env('ZKTECO_ADMS_ATTPHOTO_DISK', 'local'),
        'path' => env('ZKTECO_ADMS_ATTPHOTO_PATH', 'zkteco/attphotos'),
    ],

    'schedule' => [
        'enabled' => (bool) env('ZKTECO_ADMS_SCHEDULE', true),
    ],
];
