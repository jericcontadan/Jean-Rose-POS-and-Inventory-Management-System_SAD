<?php

// Jean Rose POS settings (see docs/04-business-rules.md).
// Values come from .env; the numbers here are defaults until the owner confirms them.
return [

    // A batch is NEAR EXPIRY when it expires within this many days (and is not yet expired).
    'near_expiry_days' => (int) env('NEAR_EXPIRY_DAYS', 30),

    // Discount given on each sale line sold from a NEAR EXPIRY batch.
    'near_expiry_discount_percent' => (float) env('NEAR_EXPIRY_DISCOUNT_PERCENT', 10),

    // Senior Citizen / PWD discount (chosen in the payment box). Never added on top of the near-expiry discount:
    // per item the higher one is used (see App\Services\DiscountService).
    'senior_pwd_discount_percent' => (float) env('SENIOR_PWD_DISCOUNT_PERCENT', 20),

    // Which categories get the Senior/PWD discount: a comma list of category_id, for example "1,3".
    // Empty (the default) means ALL categories.
    'senior_pwd_category_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('SENIOR_PWD_CATEGORY_IDS', ''))))),

    // How emails are sent: 'brevo' = Brevo REST API first, Gmail SMTP as backup; 'smtp' = Gmail SMTP only.
    // If BREVO_API_KEY is empty, BrevoMailService uses SMTP automatically (docs/api/brevo-integration.md).
    'mail_via' => env('POS_MAIL_VIA', 'brevo'),

];
