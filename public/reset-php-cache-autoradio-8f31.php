<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

clearstatcache(true);

if (! function_exists('opcache_reset')) {
    http_response_code(503);
    echo "OPcache non disponibile in questo ambiente PHP.\n";
    exit;
}

if (! @opcache_reset()) {
    http_response_code(503);
    echo "Aruba non consente il reset di OPcache da questo script.\n";
    exit;
}

echo "OPcache PHP svuotata correttamente. Cancella ora questo file dal server.\n";
