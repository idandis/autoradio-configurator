<?php

return [
    'url' => env('WAHA_URL', 'http://127.0.0.1:3000'),
    'key' => env('WAHA_API_KEY'),
    'session' => env('WAHA_SESSION', 'default'),
    'test_number' => env('WAHA_TEST_NUMBER'),
    'test_enabled' => env('WAHA_TEST_ENABLED', false),
    'pdf_browser' => env('WAHA_PDF_BROWSER', collect(['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'])->first(fn ($path) => is_executable($path))),
];
