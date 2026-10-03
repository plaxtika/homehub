<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
start_session();

function back(string $query = ''): never
{
    header('Location: ./' . ($query !== '' ? '?' . $query : ''));
    exit;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    back();
}

if (isset($_GET['error'])) {
    back('login_error=' . rawurlencode((string) ($_GET['error_description'] ?? $_GET['error'])));
}

if (!isset($_GET['code'])) {
    if (cfg('dry_run')) {
        session_regenerate_id(true);
        $_SESSION['sub'] = Auth::allowedSub();
        Auth::storeToken($_SESSION['sub'], 'dry-run-token', 60 * 60 * 24 * 60, 'Testnutzer (Dry-Run)', '');
        back();
    }
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    header('Location: ' . LinkedIn::authorizeUrl($_SESSION['oauth_state']));
    exit;
}

try {
    if (!hash_equals($_SESSION['oauth_state'] ?? '', (string) ($_GET['state'] ?? ''))) {
        throw new RuntimeException('Ungültiger Login-Status – bitte erneut versuchen.');
    }
    unset($_SESSION['oauth_state']);
    $token = LinkedIn::exchangeCode((string) $_GET['code']);
    $user = LinkedIn::userinfo($token['access_token']);

    if (Auth::allowedSub() === '' || !hash_equals(Auth::allowedSub(), (string) $user['sub'])) {
        // Erst-Einrichtung: ID anzeigen, damit sie in config.php eingetragen werden kann.
        $_SESSION['pending_sub'] = $user['sub'];
        back('login_error=' . rawurlencode('Dieses Konto ist nicht freigeschaltet.'));
    }

    session_regenerate_id(true);
    $_SESSION['sub'] = $user['sub'];
    unset($_SESSION['pending_sub']);
    Auth::storeToken($user['sub'], $token['access_token'], (int) $token['expires_in'], (string) ($user['name'] ?? ''), (string) ($user['picture'] ?? ''));
    back();
} catch (Throwable $e) {
    error_log('[linkedin-messe] ' . $e);
    back('login_error=' . rawurlencode($e->getMessage()));
}
