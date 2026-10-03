<?php
declare(strict_types=1);

final class Posts
{
    public const MAX_CHARS = 3000;
    public const MAX_IMAGES = 20;
    private const MAX_ATTEMPTS = 3;

    public static function list(): array
    {
        $posts = Db::all('SELECT * FROM posts ORDER BY COALESCE(scheduled_at, published_at, updated_at) DESC');
        return array_map(fn ($p) => self::present($p), $posts);
    }

    public static function get(string $id): ?array
    {
        $p = Db::one('SELECT * FROM posts WHERE id = ?', [$id]);
        return $p ? self::present($p) : null;
    }

    /** Legt an oder aktualisiert (Client vergibt die ID, damit offline erstellte Entwürfe synchronisiert werden können). */
    public static function save(string $id, array $in): array
    {
        if (!preg_match('/^[a-f0-9-]{8,40}$/', $id)) {
            throw new InvalidArgumentException('Ungültige ID');
        }
        $existing = Db::one('SELECT status FROM posts WHERE id = ?', [$id]);
        if ($existing && in_array($existing['status'], ['publishing', 'published'], true)) {
            throw new InvalidArgumentException('Veröffentlichte Beiträge können nicht mehr bearbeitet werden.');
        }
        $text = (string) ($in['text'] ?? '');
        $tags = self::cleanTags((array) ($in['hashtags'] ?? []));
        if (mb_strlen(LinkedIn::plain(self::compose($text, $tags))) > self::MAX_CHARS) {
            throw new InvalidArgumentException('Text ist länger als ' . self::MAX_CHARS . ' Zeichen.');
        }
        $fields = [
            'template_id' => isset($in['template_id']) ? (int) $in['template_id'] : null,
            'title_points' => (string) ($in['title_points'] ?? ''),
            'body_points' => (string) ($in['body_points'] ?? ''),
            'text' => $text,
            'visibility' => ($in['visibility'] ?? 'PUBLIC') === 'CONNECTIONS' ? 'CONNECTIONS' : 'PUBLIC',
            'voice' => ($in['voice'] ?? 'ich') === 'wir' ? 'wir' : 'ich',
            'hashtags' => json_encode($tags, JSON_UNESCAPED_UNICODE),
        ];
        $now = time();
        if ($existing) {
            Db::exec('UPDATE posts SET template_id = ?, title_points = ?, body_points = ?, text = ?, visibility = ?, voice = ?, hashtags = ?, updated_at = ? WHERE id = ?',
                [...array_values($fields), $now, $id]);
        } else {
            Db::exec('INSERT INTO posts (id, template_id, title_points, body_points, text, visibility, voice, hashtags, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, ...array_values($fields), $now, $now]);
        }
        if (isset($in['images']) && is_array($in['images'])) {
            $ids = array_slice(array_values(array_map('strval', array_column($in['images'], 'id'))), 0, self::MAX_IMAGES);
            $alts = array_column($in['images'], 'alt', 'id');
            Images::sync($id, $ids, $alts);
        }
        return self::get($id);
    }

    /** Hashtags säubern: ohne #, ohne Leerzeichen, keine Dubletten, max. 30. */
    public static function cleanTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            $t = preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim(trim((string) $t), '#'));
            if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                $out[] = $t;
            }
        }
        return array_slice($out, 0, 30);
    }

    /** Endgültiger Beitragstext: Text + Hashtag-Zeile. */
    public static function compose(string $text, array $tags): string
    {
        $text = rtrim($text);
        if (!$tags) {
            return $text;
        }
        $line = implode(' ', array_map(fn ($t) => '#' . $t, $tags));
        return $text === '' ? $line : $text . "\n\n" . $line;
    }

    public static function delete(string $id): void
    {
        $p = Db::one('SELECT status FROM posts WHERE id = ?', [$id]);
        if ($p && $p['status'] === 'publishing') {
            throw new InvalidArgumentException('Beitrag wird gerade veröffentlicht.');
        }
        Images::deleteForPost($id);
        Db::exec('DELETE FROM posts WHERE id = ?', [$id]);
    }

    public static function schedule(string $id, int $at): array
    {
        if ($at < time() - 60) {
            throw new InvalidArgumentException('Der Zeitpunkt liegt in der Vergangenheit.');
        }
        self::assertPublishable($id);
        Db::exec("UPDATE posts SET status = 'scheduled', scheduled_at = ?, error = NULL, attempts = 0, updated_at = ? WHERE id = ? AND status IN ('draft', 'scheduled', 'failed')",
            [$at, time(), $id]);
        return self::get($id);
    }

    public static function unschedule(string $id): array
    {
        Db::exec("UPDATE posts SET status = 'draft', scheduled_at = NULL, updated_at = ? WHERE id = ? AND status IN ('scheduled', 'failed')", [time(), $id]);
        return self::get($id);
    }

    /** Sofort veröffentlichen (aus der App heraus). */
    public static function publishNow(string $id): array
    {
        self::assertPublishable($id);
        $claimed = Db::exec("UPDATE posts SET status = 'publishing', updated_at = ? WHERE id = ? AND status IN ('draft', 'scheduled', 'failed')", [time(), $id]);
        if ($claimed !== 1) {
            throw new InvalidArgumentException('Beitrag ist bereits in Bearbeitung oder veröffentlicht.');
        }
        self::doPublish($id, false);
        return self::get($id);
    }

    /** Wird von cron.php aufgerufen. Liefert Protokollzeilen. */
    public static function runDue(): array
    {
        $log = [];
        // Hängen gebliebene Veröffentlichungen (z. B. Timeout) nicht blind wiederholen – Doppelpost-Gefahr.
        $stuck = Db::exec("UPDATE posts SET status = 'failed', error = 'Abbruch während der Veröffentlichung – bitte auf LinkedIn prüfen, ob der Beitrag schon online ist.' WHERE status = 'publishing' AND updated_at < ?", [time() - 900]);
        if ($stuck) {
            $log[] = "$stuck hängende Veröffentlichung(en) als fehlgeschlagen markiert";
        }
        foreach (Db::all("SELECT id FROM posts WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at", [time()]) as $row) {
            $claimed = Db::exec("UPDATE posts SET status = 'publishing', updated_at = ? WHERE id = ? AND status = 'scheduled'", [time(), $row['id']]);
            if ($claimed !== 1) {
                continue;
            }
            $ok = self::doPublish($row['id'], true);
            $log[] = $row['id'] . ': ' . ($ok ? 'veröffentlicht' : 'Fehler');
        }
        return $log;
    }

    public static function deleteOnLinkedIn(string $id): array
    {
        $p = Db::one('SELECT * FROM posts WHERE id = ?', [$id]);
        if (!$p || $p['status'] !== 'published' || !$p['linkedin_urn']) {
            throw new InvalidArgumentException('Beitrag ist nicht veröffentlicht.');
        }
        $token = Auth::token() ?? throw new RuntimeException('LinkedIn-Login abgelaufen – bitte neu einloggen.');
        LinkedIn::deletePost($token, $p['linkedin_urn']);
        Db::exec("UPDATE posts SET status = 'draft', linkedin_urn = NULL, published_at = NULL, updated_at = ? WHERE id = ?", [time(), $id]);
        return self::get($id);
    }

    private static function assertPublishable(string $id): void
    {
        $p = Db::one('SELECT text, hashtags FROM posts WHERE id = ?', [$id]) ?? throw new InvalidArgumentException('Beitrag nicht gefunden.');
        if (trim(self::compose($p['text'], json_decode($p['hashtags'], true) ?: [])) === '' && !Images::forPost($id)) {
            throw new InvalidArgumentException('Beitrag ist leer.');
        }
        if (Auth::token() === null) {
            throw new InvalidArgumentException('LinkedIn-Login abgelaufen – bitte neu einloggen, bevor du veröffentlichst oder planst.');
        }
    }

    private static function doPublish(string $id, bool $retryable): bool
    {
        $p = Db::one('SELECT * FROM posts WHERE id = ?', [$id]);
        try {
            $token = Auth::token() ?? throw new RuntimeException('LinkedIn-Login abgelaufen – bitte neu einloggen.');
            $images = array_map(fn ($i) => ['path' => Images::path($i['filename']), 'alt' => $i['alt']], Images::forPost($id));
            $text = self::compose($p['text'], json_decode($p['hashtags'], true) ?: []);
            $urn = LinkedIn::publish($token, (string) Db::setting('li_sub'), $text, $images, $p['visibility']);
            Db::exec("UPDATE posts SET status = 'published', linkedin_urn = ?, published_at = ?, error = NULL, warning = ?, updated_at = ? WHERE id = ?",
                [$urn, time(), LinkedIn::$lastWarning, time(), $id]);
            return true;
        } catch (Throwable $e) {
            $attempts = (int) $p['attempts'] + 1;
            $again = $retryable && $attempts < self::MAX_ATTEMPTS && !str_contains($e->getMessage(), 'Login abgelaufen');
            Db::exec('UPDATE posts SET status = ?, attempts = ?, error = ?, scheduled_at = ?, updated_at = ? WHERE id = ?', [
                $again ? 'scheduled' : 'failed', $attempts, $e->getMessage(),
                $again ? time() + 300 : $p['scheduled_at'], time(), $id,
            ]);
            if (!$retryable) {
                throw $e;
            }
            return false;
        }
    }

    private static function present(array $p): array
    {
        $p['images'] = array_map(fn ($i) => [
            'id' => $i['id'], 'width' => (int) $i['width'], 'height' => (int) $i['height'], 'alt' => $i['alt'],
            'url' => 'media.php?id=' . rawurlencode($i['id']),
        ], Images::forPost($p['id']));
        $p['hashtags'] = json_decode($p['hashtags'] ?? '[]', true) ?: [];
        $p['url'] = $p['linkedin_urn'] ? LinkedIn::postUrl($p['linkedin_urn']) : null;
        foreach (['template_id', 'scheduled_at', 'published_at', 'attempts'] as $k) {
            $p[$k] = $p[$k] === null ? null : (int) $p[$k];
        }
        return $p;
    }
}
