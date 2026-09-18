<?php

return [
    'mode' => env('STRIPE_MODE', 'test'),
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
];
