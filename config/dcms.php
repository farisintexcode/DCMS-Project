<?php

/** Plan.md §4; Module.md M01/M12; production grants require business sign-off. */
return [
    'branding' => [
        'name' => 'DebtOps',
        'system_name' => 'Debt Collection Management System',
        'developer' => 'InteXcode',
        'logo' => 'images/brand/debtops-logo.png',
        'icon' => 'images/brand/debtops-icon-transparent.png',
    ],
    'e2e_password' => env('DCMS_E2E_PASSWORD'),
    'search_key' => env('DCMS_SEARCH_KEY'),
    'name_prefix_min' => 3,
    'business_timezone' => env('DCMS_BUSINESS_TIMEZONE', 'Asia/Kuala_Lumpur'),
    'terms_version' => env('DCMS_TERMS_VERSION'),
    'privacy_url' => env('DCMS_PRIVACY_URL'),
    'permissions' => [
        'admin.access', 'rider.access', 'dashboard.view',
        'customers.view', 'customers.create', 'customers.update', 'customers.update_contacts',
        'customers.categorize', 'customers.manage_categories', 'customers.view_sensitive', 'riders.manage', 'users.invite',
        'assignments.view', 'assignments.manage', 'assignments.reopen', 'imports.view', 'imports.map', 'imports.confirm',
        'tasks.view', 'tasks.create', 'tasks.update', 'tasks.assign', 'tasks.reassign',
        'tasks.cancel', 'tasks.monitor', 'tasks.submit', 'tasks.verify',
        'attendance.clock', 'attendance.view_own', 'attendance.view_all', 'attendance.correct',
        'attendance.approve_correction', 'attendance.export', 'attendance.configure',
        'communication-permission.create', 'communication-permission.update', 'communication-permission.block', 'communication-permission.approve', 'communication-permission.override', 'calls.record', 'communications.view', 'communications.compose', 'communications.send',
        'communications.bulk_send', 'communications.generate_letter', 'templates.manage', 'templates.approve',
        'whatsapp.message', 'whatsapp.call', 'reports.view', 'reports.export',
        'roles.manage', 'scopes.manage', 'audit.view', 'integrations.manage', 'monitoring.view',
    ],
    'rider_permissions' => ['rider.access', 'tasks.view', 'tasks.submit', 'attendance.clock', 'attendance.view_own'],
    'rider_only_permissions' => ['rider.access', 'tasks.submit', 'attendance.clock', 'attendance.view_own'],
    'reserved_permissions' => ['communications.generate_letter', 'communication-permission.create', 'communication-permission.update', 'communication-permission.block', 'communication-permission.approve', 'communication-permission.override', 'roles.manage', 'scopes.manage', 'templates.approve', 'attendance.approve_correction', 'integrations.manage', 'customers.view_sensitive', 'users.invite', 'assignments.reopen'],
    'queues' => ['default', 'imports', 'exports', 'email', 'whatsapp', 'webhooks', 'media'],
];
