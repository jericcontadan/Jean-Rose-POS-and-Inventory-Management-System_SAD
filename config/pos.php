<?php

// Jean Rose POS settings (see docs/04-business-rules.md).
// Values come from .env; the numbers here are defaults until the owner confirms them.
return [

    // A batch is NEAR EXPIRY when it expires within this many days (and is not yet expired).
    'near_expiry_days' => (int) env('NEAR_EXPIRY_DAYS', 30),

    // Discount given on each sale line sold from a NEAR EXPIRY batch.
    'near_expiry_discount_percent' => (float) env('NEAR_EXPIRY_DISCOUNT_PERCENT', 10),

];
