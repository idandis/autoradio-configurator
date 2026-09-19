<?php

return [
    'enabled' => env('ITALIAN_CHECKOUT_ENABLED', env('APP_ENV') === 'local'),
    'hosts' => [
        'autoradioitaliano.it',
        'www.autoradioitaliano.it',
        'autoradiocanario.com',
        'www.autoradiocanario.com',
        'config.autoradiocanario.com',
    ],
    'origin' => env('ITALIAN_CHECKOUT_ORIGIN', 'https://www.autoradioitaliano.it'),
    'mail_enabled' => env('ITALIAN_ORDER_MAIL_ENABLED', false),
    'mail_from_name' => 'Autoradio Italiano',
];
