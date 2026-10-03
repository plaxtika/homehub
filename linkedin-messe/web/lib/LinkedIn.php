<?php
declare(strict_types=1);

/** Schmaler Client für OAuth, Images API und Posts API von LinkedIn. */
final class LinkedIn
{
    private const API_DEFAULT = 'https://api.linkedin.com';
    private const OAUTH = 'https://www.linkedin.com/oauth/v2';

    private static function api(): string
    {
        return (string) cfg('linkedin_api_base', self::API_DEFAULT); // überschreibbar für Tests
    }

    public static function authorizeUrl(string $state): string
    {
        return self::OAUTH . '/authorization?' . http_build_query([
            'response_type' => 'code',
            'client_id' => cfg('linkedin_client_id'),
            'redirect_uri' => Auth::redirectUri(),
            'state' => $state,
            'scope' => Auth::SCOPES,
        ]);
    }

    /** @return array{access_token:string, expires_in:int} */
    public static function exchangeCode(string $code): array
    {
        $res = self::request('POST', self::OAUTH . '/accessToken', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => Auth::redirectUri(),
            'client_id' => cfg('linkedin_client_id'),
            'client_secret' => cfg('linkedin_client_secret'),
        ]));
        self::expect($res, 200, 'Token-Abruf');
        return json_decode($res['body'], true);
    }

    /** @return array{sub:string, name:string, picture?:string} */
    public static function userinfo(string $token): array
    {
        $res = self::request('GET', self::api() . '/v2/userinfo', ['Authorization: Bearer ' . $token]);
        self::expect($res, 200, 'Profil-Abruf');
        return json_decode($res['body'], true);
    }

    /**
     * Lädt die Bilder hoch und veröffentlicht den Beitrag.
     * @param list<array{path:string, alt:string}> $images
     * @return string URN des Beitrags (urn:li:share:… oder urn:li:ugcPost:…)
     */
    public static function publish(string $token, string $sub, string $text, array $images, string $visibility): string
    {
        self::$lastWarning = null;
        if (cfg('dry_run')) {
            usleep(300000);
            return 'urn:li:share:dryrun' . random_int(100000, 999999);
        }
        $author = 'urn:li:person:' . $sub;
        $media = [];
        foreach ($images as $img) {
            $media[] = ['id' => self::uploadImage($token, $author, $img['path']), 'altText' => mb_substr($img['alt'], 0, 4086)];
        }

        $body = [
            'author' => $author,
            'visibility' => $visibility === 'CONNECTIONS' ? 'CONNECTIONS' : 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];
        if (count($media) === 1) {
            $body['content'] = ['media' => $media[0]];
        } elseif (count($media) > 1) {
            $body['content'] = ['multiImage' => ['images' => $media]];
        }

        $res = self::createPost($token, $body, $text, Partners::urnMap(true));
        if ($res['status'] >= 400 && $res['status'] < 500 && $res['status'] !== 401
            && Partners::urnMap(true) !== Partners::urnMap(false) && preg_match('/@\[[^\]]+\]/', $text)) {
            // Personen-Erwähnung abgelehnt? Dann ohne Personen-Verknüpfung erneut versuchen.
            $res = self::createPost($token, $body, $text, Partners::urnMap(false));
            self::$lastWarning = 'Personen-Verknüpfung wurde von LinkedIn abgelehnt – Namen stehen als normaler Text im Beitrag.';
        }
        self::expect($res, 201, 'Veröffentlichen');
        $urn = $res['headers']['x-restli-id'] ?? $res['headers']['x-linkedin-id'] ?? '';
        if ($urn === '') {
            throw new RuntimeException('LinkedIn hat keine Beitrags-ID zurückgegeben.');
        }
        return $urn;
    }

    public static ?string $lastWarning = null;

    private static function createPost(string $token, array $body, string $text, array $mentions): array
    {
        $body['commentary'] = self::littleText($text, $mentions);
        return self::request('POST', self::api() . '/rest/posts', self::restHeaders($token), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function deletePost(string $token, string $urn): void
    {
        if (cfg('dry_run')) {
            return;
        }
        $res = self::request('DELETE', self::api() . '/rest/posts/' . rawurlencode($urn), self::restHeaders($token));
        if ($res['status'] !== 204 && $res['status'] !== 404) {
            self::expect($res, 204, 'Löschen');
        }
    }

    public static function postUrl(string $urn): string
    {
        return 'https://www.linkedin.com/feed/update/' . $urn . '/';
    }

    private static function uploadImage(string $token, string $author, string $path): string
    {
        $res = self::request('POST', self::api() . '/rest/images?action=initializeUpload', self::restHeaders($token),
            json_encode(['initializeUploadRequest' => ['owner' => $author]]));
        self::expect($res, 200, 'Bild-Upload vorbereiten');
        $value = json_decode($res['body'], true)['value'] ?? [];
        if (empty($value['uploadUrl']) || empty($value['image'])) {
            throw new RuntimeException('Unerwartete Antwort beim Bild-Upload.');
        }
        $up = self::request('PUT', $value['uploadUrl'], [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/octet-stream',
        ], file_get_contents($path));
        if ($up['status'] < 200 || $up['status'] >= 300) {
            self::expect($up, 201, 'Bild-Upload');
        }
        return $value['image'];
    }

    /**
     * Wandelt Klartext ins „little text format“ der Posts API: reservierte Zeichen
     * werden escaped, #Hashtags werden als Hashtag-Template übergeben und
     * @[Firma] wird zur Erwähnung, wenn für die Firma eine Organisations-ID hinterlegt ist.
     * @param array<string,string> $mentions Name → URN
     */
    public static function littleText(string $text, array $mentions = []): string
    {
        $parts = preg_split('/(@\[[^\]\n]+\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $name = substr($part, 2, -1);
                $out .= isset($mentions[$name]) ? '@[' . $name . '](' . $mentions[$name] . ')' : self::escape($name);
            } else {
                $out .= self::escape($part);
            }
        }
        return $out;
    }

    private static function escape(string $text): string
    {
        $escaped = preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $text);
        return preg_replace_callback('/(^|\s)\\\\#((?:[\p{L}\p{N}]|\\\\_)+)/u', function (array $m): string {
            $tag = str_replace('\\_', '_', $m[2]);
            return $m[1] . '{hashtag|\\#|' . $tag . '}';
        }, $escaped);
    }

    /** Text so, wie Leser ihn sehen (für Zeichenzählung): @[Firma] → Firma. */
    public static function plain(string $text): string
    {
        return preg_replace('/@\[([^\]\n]+)\]/u', '$1', $text);
    }

    private static function restHeaders(string $token): array
    {
        return [
            'Authorization: Bearer ' . $token,
            'LinkedIn-Version: ' . cfg('linkedin_version', '202509'),
            'X-Restli-Protocol-Version: 2.0.0',
            'Content-Type: application/json',
        ];
    }

    private static function expect(array $res, int $status, string $what): void
    {
        if ($res['status'] === $status) {
            return;
        }
        $msg = json_decode($res['body'], true)['message'] ?? mb_substr($res['body'], 0, 300);
        if ($res['status'] === 401) {
            $msg = 'LinkedIn-Login abgelaufen – bitte neu einloggen. (' . $msg . ')';
        }
        throw new RuntimeException("$what fehlgeschlagen (HTTP {$res['status']}): $msg");
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private static function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Netzwerkfehler zu LinkedIn: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $out, 'headers' => $respHeaders];
    }
}
