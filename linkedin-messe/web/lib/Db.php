<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
            self::$pdo = $pdo;
            self::migrate($pdo);
        }
        return self::$pdo;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Führt aus und liefert die Anzahl betroffener Zeilen. */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function setting(string $key, ?string $default = null): ?string
    {
        $row = self::one('SELECT value FROM settings WHERE key = ?', [$key]);
        return $row['value'] ?? $default;
    }

    public static function setSetting(string $key, ?string $value): void
    {
        self::exec('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
    }

    private static function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $pdo->exec(<<<SQL
                CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
                CREATE TABLE templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    style_prompt TEXT NOT NULL DEFAULT '',
                    examples TEXT NOT NULL DEFAULT '[]',
                    provisional INTEGER NOT NULL DEFAULT 0,
                    sort INTEGER NOT NULL DEFAULT 0
                );
                CREATE TABLE posts (
                    id TEXT PRIMARY KEY,
                    template_id INTEGER,
                    title_points TEXT NOT NULL DEFAULT '',
                    body_points TEXT NOT NULL DEFAULT '',
                    text TEXT NOT NULL DEFAULT '',
                    visibility TEXT NOT NULL DEFAULT 'PUBLIC',
                    status TEXT NOT NULL DEFAULT 'draft',
                    scheduled_at INTEGER,
                    published_at INTEGER,
                    linkedin_urn TEXT,
                    error TEXT,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                );
                CREATE INDEX posts_due ON posts (status, scheduled_at);
                CREATE TABLE images (
                    id TEXT PRIMARY KEY,
                    post_id TEXT NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                    position INTEGER NOT NULL DEFAULT 0,
                    filename TEXT NOT NULL,
                    width INTEGER,
                    height INTEGER,
                    alt TEXT NOT NULL DEFAULT ''
                );
                PRAGMA user_version = 1;
            SQL);
            self::seedTemplates($pdo);
        }
        if ($version < 2) {
            $pdo->exec(<<<SQL
                ALTER TABLE posts ADD COLUMN voice TEXT NOT NULL DEFAULT 'ich';
                CREATE TABLE partners (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    org_id TEXT NOT NULL DEFAULT '',
                    page_url TEXT NOT NULL DEFAULT ''
                );
                PRAGMA user_version = 2;
            SQL);
            $st = $pdo->prepare('INSERT OR IGNORE INTO partners (name, page_url) VALUES (?, ?)');
            foreach ([
                ['Sonepar Deutschland GmbH', 'https://www.linkedin.com/company/sonepardeutschland/'],
                ['Vaillant Deutschland', ''], ['Bosch Home Comfort Group', ''], ['LAPP Gruppe', ''], ['Eaton', ''],
            ] as $p) {
                $st->execute($p);
            }
            self::seedExamples($pdo);
        }
        if ($version < 3) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN hashtags TEXT NOT NULL DEFAULT '[]'; PRAGMA user_version = 3;");
            $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('default_hashtags', '[\"Sonepar\",\"teamsonepar\",\"Elektrobranche\",\"Elektrohandwerk\"]')");
        }
        if ($version < 4) {
            $pdo->exec("ALTER TABLE partners ADD COLUMN kind TEXT NOT NULL DEFAULT 'org'; ALTER TABLE posts ADD COLUMN warning TEXT; PRAGMA user_version = 4;");
        }
    }

    /** Beispielposts in die Vorlagen übernehmen – nur wo noch keine eigenen Beispiele stehen. */
    private static function seedExamples(PDO $pdo): void
    {
        $ex = require __DIR__ . '/seed_examples.php';
        $map = [
            'Messe-Highlight' => ['thalmeier', 'johannes', 'hellmann'],
            'Produktneuheit' => ['johannes', 'emami', 'thalmeier'],
            'Kundengespräch & Team' => ['binder', 'haber', 'emami', 'hellmann'],
        ];
        $base = 'Persönliches LinkedIn-Profil eines Sonepar-Deutschland-Mitarbeiters. '
            . 'Ton: herzlich, positiv, fachlich; Wertschätzung für Kunden, Lieferantenpartner und Team. Eher Fließtext in kurzen Absätzen als Aufzählungen. '
            . 'Oft eine kurze Einstiegszeile (gern mit einem Emoji), dann 3–5 Absätze, am Ende Dank oder Ausblick. '
            . 'Partnerfirmen werden namentlich erwähnt. Hashtags am Ende (z. B. #Sonepar #Elektrobranche plus Thema/Ort).';
        $focus = [
            'Messe-Highlight' => ' Fokus: Event/Messe, Highlights des Tages, Begegnungen, Ausblick oder Einladung.',
            'Produktneuheit' => ' Fokus: eine Neuheit oder Lösung eines Lieferantenpartners und ihr konkreter Nutzen für Elektrohandwerk/Industrie. Keine technischen Daten erfinden.',
            'Kundengespräch & Team' => ' Fokus: Austausch mit Kunden/Partnern, Dank an Gastgeber und Team, Partnerschaft.',
        ];
        $st = $pdo->prepare("UPDATE templates SET examples = ?, style_prompt = ?, provisional = 0 WHERE name = ? AND examples = '[]'");
        foreach ($map as $name => $keys) {
            $st->execute([json_encode(array_map(fn ($k) => $ex[$k], $keys), JSON_UNESCAPED_UNICODE), $base . $focus[$name], $name]);
        }
    }

    private static function seedTemplates(PDO $pdo): void
    {
        $base = 'Schreibe als Mitarbeiter von Sonepar Deutschland auf dem eigenen, persönlichen LinkedIn-Profil (Ich-Form, nicht Firmen-„Wir“). '
            . 'Ton: fachlich, nahbar, positiv, ohne Marketing-Floskeln. Zielgruppe: Elektrohandwerk, Industrie, Planer, Lieferantenpartner.';
        $templates = [
            ['Messe-Highlight', $base . ' Fokus: Eindruck vom Messestand/Messetag, Highlights, Begegnungen. Schließe mit einer Einladung zum Austausch oder Standbesuch.'],
            ['Produktneuheit', $base . ' Fokus: eine Neuheit oder Lösung eines Lieferantenpartners, konkreter Nutzen für Kunden im Elektrohandwerk. Hersteller korrekt nennen, keine technischen Daten erfinden.'],
            ['Kundengespräch & Team', $base . ' Fokus: Gespräche, Kunden, Kolleginnen und Kollegen, Dank an das Team. Personen nur nennen, wenn sie in den Stichpunkten stehen.'],
        ];
        $st = $pdo->prepare('INSERT INTO templates (name, style_prompt, examples, provisional, sort) VALUES (?, ?, ?, 1, ?)');
        foreach ($templates as $i => [$name, $prompt]) {
            $st->execute([$name, $prompt, '[]', $i]);
        }
    }
}
