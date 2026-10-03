<?php
declare(strict_types=1);

/**
 * Login über „Sign In with LinkedIn (OpenID Connect)“. Derselbe Login liefert das
 * Token zum Posten; es wird verschlüsselt in der DB abgelegt, damit cron.php es nutzen kann.
 */
final class Auth
{
    public const SCOPES = 'openid profile w_member_social';

    public static function redirectUri(): string
    {
        return rtrim((string) cfg('base_url'), '/') . '/oauth.php';
    }

    public static function isLoggedIn(): bool
    {
        start_session();
        $sub = $_SESSION['sub'] ?? null;
        return $sub !== null && $sub === self::allowedSub();
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            json_out(['error' => 'Nicht eingeloggt', 'login' => true], 401);
        }
    }

    public static function allowedSub(): string
    {
        return cfg('dry_run') && cfg('allowed_sub', '') === '' ? 'dryrun' : (string) cfg('allowed_sub', '');
    }

    public static function csrfToken(): string
    {
        start_session();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    public static function checkCsrf(): void
    {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals(self::csrfToken(), $sent)) {
            json_out(['error' => 'Ungültiges CSRF-Token – bitte Seite neu laden.'], 403);
        }
    }

    /** Speichert Token + Profil nach erfolgreichem Login. */
    public static function storeToken(string $sub, string $token, int $expiresIn, string $name, string $picture): void
    {
        Db::setSetting('li_sub', $sub);
        Db::setSetting('li_token', self::encrypt($token));
        Db::setSetting('li_expires', (string) (time() + $expiresIn));
        Db::setSetting('li_name', $name);
        Db::setSetting('li_picture', $picture);
    }

    public static function token(): ?string
    {
        $enc = Db::setting('li_token');
        if ($enc === null || (int) Db::setting('li_expires', '0') < time()) {
            return null;
        }
        return self::decrypt($enc);
    }

    public static function profile(): array
    {
        return [
            'sub' => Db::setting('li_sub'),
            'name' => Db::setting('li_name', ''),
            'picture' => Db::setting('li_picture', ''),
            'expires' => (int) Db::setting('li_expires', '0'),
        ];
    }

    private static function key(): string
    {
        $secret = (string) cfg('app_secret', '');
        if (strlen($secret) < 32 || $secret === 'BITTE-AENDERN') {
            throw new RuntimeException('app_secret in config.php fehlt oder ist zu kurz.');
        }
        return hash('sha256', $secret, true);
    }

    private static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
}
