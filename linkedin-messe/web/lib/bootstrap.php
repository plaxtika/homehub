<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';

$configFile = getenv('LMESSE_CONFIG') ?: APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config.php fehlt – bitte config.sample.php kopieren und ausfüllen.');
}
$GLOBALS['config'] = require $configFile;
date_default_timezone_set(cfg('timezone', 'Europe/Berlin'));

require APP_ROOT . '/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

foreach ([DATA_DIR, DATA_DIR . '/images'] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}
// Zweite Absicherung neben der Haupt-.htaccess, falls mod_rewrite fehlt.
foreach ([DATA_DIR, APP_ROOT . '/vendor'] as $dir) {
    if (is_dir($dir) && !is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "Require all denied\n");
    }
}

function cfg(string $key, mixed $default = null): mixed
{
    return $GLOBALS['config'][$key] ?? $default;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('lmesse');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => parse_url(cfg('base_url', ''), PHP_URL_PATH) ?: '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    ini_set('session.save_path', DATA_DIR);
    session_start();
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
