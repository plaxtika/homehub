<?php
declare(strict_types=1);

// Aufruf per Cronjob alle 5 Minuten:
//   CLI:  php /pfad/zu/linkedin/cron.php
//   HTTP: https://plaxtika.de/linkedin/cron.php?key=<cron_key>   (cron_key zeigt `php cron.php --key`)
require __DIR__ . '/lib/bootstrap.php';

$cronKey = substr(hash_hmac('sha256', 'cron', (string) cfg('app_secret')), 0, 32);

if (PHP_SAPI === 'cli') {
    if (in_array('--key', $argv, true)) {
        echo $cronKey, PHP_EOL;
        exit;
    }
} elseif (!hash_equals($cronKey, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}

$lock = fopen(DATA_DIR . '/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit("läuft bereits\n");
}
header('Content-Type: text/plain; charset=utf-8');
$log = Posts::runDue();
echo date('c'), ' ', $log ? implode("\n", $log) : 'nichts fällig', "\n";
flock($lock, LOCK_UN);
