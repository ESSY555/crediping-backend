<?php

/*
| The platform's standard message templates. When a business connects its
| WhatsApp number these are submitted to its WhatsApp Business Account so
| invoices, reminders and receipts can be sent cold (outside the 24-hour
| service window). Businesses never write or submit templates themselves.
|
| `content` uses Crediping's named placeholders; Meta receives the same body
| with positional {{1}}, {{2}}… in the order the placeholders first appear
| (see WhatsAppCloudApi::payloadFor / MessageTemplate::placeholders).
| All are Utility-category, i.e. charged against the plan's included messages.
*/

return [
    'language' => 'en',

    'templates' => [
        [
            'name' => 'flowcrm_invoice_sent',
            'category' => 'utility',
            'content' => "Hello {{customer_name}}, {{business_name}} has sent you invoice {{invoice_number}} for {{amount}}, due {{due_date}}. Reply to this message if you have any questions.",
        ],
        [
            'name' => 'flowcrm_payment_reminder',
            'category' => 'utility',
            'content' => "Hello {{customer_name}}, a reminder from {{business_name}}: invoice {{invoice_number}} has a balance of {{balance_due}} due on {{due_date}}. Thank you.",
        ],
        [
            'name' => 'flowcrm_payment_overdue',
            'category' => 'utility',
            'content' => "Hello {{customer_name}}, invoice {{invoice_number}} from {{business_name}} is {{days_overdue}} day(s) overdue with a balance of {{balance_due}}. Please make payment or reply to arrange one.",
        ],
        [
            'name' => 'flowcrm_payment_received',
            'category' => 'utility',
            'content' => "Hello {{customer_name}}, {{business_name}} has received your payment of {{amount}} for invoice {{invoice_number}}. Thank you for your business.",
        ],
        [
            'name' => 'flowcrm_custom_message',
            'category' => 'utility',
            'content' => "Hello {{customer_name}}, {{business_name}} sent you a message: {{message}}. Reply to this message if you have any questions.",
        ],
    ],
];
