<?php

return [
    'complete_frame_products' => ['pantalla-android-16-con-carplay-dsp-y-gps-para-mitsubishi-asx-2016-2022-potencia-conectividad-total-y-experiencia-multimedia-premium'],
    'url' => env('WAHA_URL', 'http://127.0.0.1:3000'),
    'key' => env('WAHA_API_KEY'),
    'session' => env('WAHA_SESSION', 'default'),
    'test_number' => env('WAHA_TEST_NUMBER'),
    'monitor_enabled' => env('WAHA_MONITOR_ENABLED', true),
    'test_enabled' => env('WAHA_TEST_ENABLED', false),
    'voice_enabled' => env('WAHA_VOICE_ENABLED', true),
    'voice_python' => env('WAHA_VOICE_PYTHON', '/opt/homebrew/bin/python3.11'),
    'voice_model' => env('WAHA_VOICE_MODEL', getenv('HOME').'/.cache/whisper/large-v3-turbo.pt'),
    'pdf_browser' => env('WAHA_PDF_BROWSER', collect(['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'])->first(fn ($path) => is_executable($path))),
];
