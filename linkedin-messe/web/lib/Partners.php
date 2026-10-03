<?php
declare(strict_types=1);

/** Firmen und Personen, die im Text als @[Name] verknüpft (erwähnt) werden können. */
final class Partners
{
    public static function all(): array
    {
        return array_map(fn ($p) => ['id' => (int) $p['id']] + $p,
            Db::all("SELECT * FROM partners ORDER BY kind = 'person', name COLLATE NOCASE"));
    }

    /** @return array<string,string> Name → URN (nur Einträge mit ID) */
    public static function urnMap(bool $withPersons = true): array
    {
        $map = [];
        foreach (Db::all("SELECT name, org_id, kind FROM partners WHERE org_id != ''") as $p) {
            if ($p['kind'] === 'person') {
                if ($withPersons) {
                    $map[$p['name']] = 'urn:li:person:' . $p['org_id'];
                }
            } else {
                $map[$p['name']] = 'urn:li:organization:' . $p['org_id'];
            }
        }
        return $map;
    }

    public static function save(?int $id, array $in): void
    {
        $name = trim(str_replace(['[', ']', '(', ')'], '', (string) ($in['name'] ?? '')));
        if ($name === '') {
            throw new InvalidArgumentException('Name fehlt.');
        }
        $kind = ($in['kind'] ?? 'org') === 'person' ? 'person' : 'org';
        $raw = trim((string) ($in['org_id'] ?? ''));
        $ref = '';
        if ($raw !== '') {
            if ($kind === 'org') {
                // Erlaubt: Zahl, urn:li:organization:123, urn:li:fsd_company:123 oder URL mit /company/123/
                $ref = preg_match('/(\d{3,})/', $raw, $m) ? $m[1] : '';
                if ($ref === '') {
                    throw new InvalidArgumentException('Die Organisations-ID muss eine Zahl sein (z. B. 1234567).');
                }
            } else {
                // Erlaubt: urn:li:person:XYZ, urn:li:fsd_profile:XYZ oder nur XYZ
                $ref = preg_match('/(?:urn:li:(?:person|fsd_profile|member):)?([A-Za-z0-9_-]{3,})$/', $raw, $m) ? $m[1] : '';
                if ($ref === '') {
                    throw new InvalidArgumentException('Unbekanntes Format der Personen-ID.');
                }
            }
        }
        $url = trim((string) ($in['page_url'] ?? ''));
        if ($id) {
            Db::exec('UPDATE partners SET name = ?, org_id = ?, page_url = ?, kind = ? WHERE id = ?', [$name, $ref, $url, $kind, $id]);
        } else {
            Db::exec('INSERT INTO partners (name, org_id, page_url, kind) VALUES (?, ?, ?, ?)', [$name, $ref, $url, $kind]);
        }
    }
}
