<?php

/** Plan.md/M14 owner-approved placeholder registry and initial wording, 18 October 2026. */
return [
    'ses_receipts_enabled' => (bool) env('MAIL_SES_RECEIPTS_ENABLED', false),
    'ses_configuration_set' => env('MAIL_SES_CONFIGURATION_SET'),
    'ses_topics' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_SES_SNS_TOPICS', ''))))),
    'delivery_enabled' => (bool) env('MAIL_LIVE_SENDING_ENABLED', false),
    'email_account' => env('MAIL_SES_ACCOUNT'),
    'daily_limit' => (int) env('MAIL_DAILY_LIMIT', 1000),
    'sandbox_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_SANDBOX_RECIPIENTS', ''))))),
    'rate_limit' => (int) env('MAIL_RATE_LIMIT_PER_SECOND', 2),
    'sandbox_enabled' => (bool) env('MAIL_SANDBOX_ENABLED', true),
    'ses_region' => env('AWS_DEFAULT_REGION', 'ap-southeast-1'),
    'retry_delays' => [30, 120, 300],
    'max_attempts' => 3,
    'attempt_timeout' => 120,
    'preview_max_recipients' => 250,
    'basis_codes' => ['EXISTING_ACCOUNT_RELATIONSHIP', 'CLIENT_AUTHORIZED_COLLECTION', 'CUSTOMER_PROVIDED_CONTACT', 'EXPLICIT_SERVICE_CONSENT', 'LEGAL_OR_REGULATORY_BASIS'],
    'preference_states' => ['allowed', 'blocked', 'unknown', 'withdrawn', 'invalid_address', 'hard_bounced', 'under_review'],
    'placeholders' => [
        'customer_name' => 'Customer name', 'account_number' => 'Selected account number',
        'outstanding_amount' => 'Outstanding amount from approved financial source',
        'due_date' => 'Payment due date from approved financial source', 'company_name' => 'Commissioning client name',
    ],
    'defaults' => [
        1 => [
            'name' => 'Friendly Payment Reminder',
            'subject' => 'Payment Reminder for Account {{account_number}}',
            'body' => <<<'TEXT'
Dear {{customer_name}},
We hope you are well.
This is a friendly reminder regarding your account {{account_number}} with {{company_name}}. Our records currently show an outstanding amount of {{outstanding_amount}}, with a due date of {{due_date}}.
If payment has already been made, please disregard this reminder. Otherwise, we kindly request that you arrange payment or contact us if you require further clarification regarding your account.
Thank you for your attention to this matter.
Regards,
{{company_name}}
TEXT,
        ],
        2 => [
            'name' => 'Outstanding Balance Follow-Up',
            'subject' => 'Follow-Up on Outstanding Balance — Account {{account_number}}',
            'body' => <<<'TEXT'
Dear {{customer_name}},
We are following up regarding the outstanding balance associated with account {{account_number}} under {{company_name}}.
As of this notice, the outstanding amount is {{outstanding_amount}} and the recorded due date is {{due_date}}.
Please review the account details and arrange payment as soon as possible. If you have already made payment or believe the information is incorrect, please contact the appropriate representative so that the account can be reviewed.
We appreciate your cooperation and prompt attention.
Regards,
{{company_name}}
TEXT,
        ],
        3 => [
            'name' => 'Final Follow-Up Notice',
            'subject' => 'Action Required: Outstanding Account {{account_number}}',
            'body' => <<<'TEXT'
Dear {{customer_name}},
This message is a further follow-up regarding account {{account_number}} with {{company_name}}.
The account currently reflects an outstanding amount of {{outstanding_amount}}, with a due date of {{due_date}}.
Please arrange payment or contact us promptly to discuss the account status. If payment has already been completed or the account details require clarification, please notify us so that the matter can be reviewed accordingly.
This notice is intended to ensure that the account receives the necessary attention and follow-up.
Regards,
{{company_name}}
TEXT,
        ],
    ],
];
