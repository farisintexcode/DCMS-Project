<?php

use App\Services\SanitizeSentryEvent;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'release' => env('SENTRY_RELEASE'),
    'send_default_pii' => false,
    'max_request_body_size' => 'none',
    'attach_stacktrace' => false,
    'traces_sample_rate' => 0.0,
    'enable_logs' => false,
    'enable_metrics' => false,
    'before_send' => [SanitizeSentryEvent::class, 'handle'],
    'breadcrumbs' => [
        'logs' => false, 'cache' => false, 'livewire' => false, 'sql_queries' => false,
        'sql_bindings' => false, 'queue_info' => false, 'command_info' => false,
        'http_client_requests' => false, 'notifications' => false,
    ],
];
