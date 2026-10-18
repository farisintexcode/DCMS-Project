<?php

/** Plan.md M04: bounded intake defaults, reviewed mappings, private storage and fail-closed scanning. */
return [
    'disk' => 's3',
    'max_kilobytes' => (int) env('IMPORT_MAX_KILOBYTES', 10240),
    'max_rows' => (int) env('IMPORT_MAX_ROWS', 2000),
    'max_columns' => 100,
    'max_sheets' => 10,
    'max_expanded_bytes' => 67108864,
    'scanner_host' => env('IMPORT_SCANNER_HOST', 'clamav'),
    'scanner_port' => (int) env('IMPORT_SCANNER_PORT', 3310),
    'scanner_timeout' => 30,
    'queue' => 'imports',
];
