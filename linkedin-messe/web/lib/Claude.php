<?php
declare(strict_types=1);

use Anthropic\Client;

/** Erzeugt Beitragstexte aus Stichpunkten über die Claude API. */
final class Claude
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'hook' => ['type' => 'string', 'description' => 'Erste Zeile/Überschrift, die im Feed vor „…mehr“ sichtbar ist'],
            'body' => ['type' => 'string', 'description' => 'Haupttext mit Absätzen, ohne Hashtags'],
            'hashtags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3–6 empfohlene Hashtags ohne #'],
            'hashtag_suggestions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '5–8 weitere passende Hashtags zur Auswahl, ohne #'],
        ],
        'required' => ['hook', 'body', 'hashtags', 'hashtag_suggestions'],
        'additionalProperties' => false,
    ];

    /**
     * @param list<string> $imagePaths Bilder, die Claude zur Einordnung sehen darf
     * @return array{hook:string, body:string, hashtags:list<string>}
     */
    public static function generate(array $template, string $voice, string $titlePoints, string $bodyPoints, array $imagePaths, string $currentText = '', string $instruction = ''): array
    {
        if (cfg('dry_run') && cfg('anthropic_api_key', '') === '') {
            return self::fake($titlePoints, $bodyPoints, $instruction);
        }

        $content = [];
        foreach (array_slice($imagePaths, 0, 4) as $path) {
            $data = Images::forVision($path);
            if ($data !== null) {
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $data]];
            }
        }
        $prompt = "Stichpunkte für die Überschrift:\n" . trim($titlePoints)
            . "\n\nStichpunkte für den Text:\n" . trim($bodyPoints)
            . "\n\nAnzahl Bilder im Beitrag: " . count($imagePaths)
            . ($content ? ' (die ersten ' . count($content) . ' siehst du oben)' : '');
        if (trim($currentText) !== '' && trim($instruction) !== '') {
            $prompt .= "\n\nAktuelle Fassung des Beitrags:\n" . $currentText
                . "\n\nÜberarbeite diese Fassung nach folgender Anweisung: " . trim($instruction);
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $client = new Client(apiKey: (string) cfg('anthropic_api_key'));
        $message = $client->beta->messages->create(
            model: (string) cfg('claude_model', 'claude-opus-5-5'),
            maxTokens: 8000,
            system: self::systemPrompt($template, $voice),
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: [
                'effort' => (string) cfg('claude_effort', 'medium'),
                'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA],
            ],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
            requestOptions: ['timeout' => 90.0, 'maxRetries' => 2],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude hat die Anfrage abgelehnt. Bitte Stichpunkte umformulieren.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('Antwort von Claude wurde abgeschnitten – bitte erneut versuchen.');
        }
        $json = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json .= $block->text;
            }
        }
        $out = json_decode($json, true);
        if (!is_array($out) || !isset($out['hook'], $out['body'], $out['hashtags'])) {
            throw new RuntimeException('Claude hat kein gültiges Ergebnis geliefert.');
        }
        $out['hashtags'] = Posts::cleanTags($out['hashtags']);
        $out['hashtag_suggestions'] = array_values(array_diff(Posts::cleanTags($out['hashtag_suggestions'] ?? []), $out['hashtags']));
        return $out;
    }

    private static function systemPrompt(array $template, string $voice): string
    {
        $examples = json_decode($template['examples'] ?? '[]', true) ?: [];
        $all = Partners::all();
        $orgs = implode(', ', array_column(array_filter($all, fn ($p) => $p['kind'] !== 'person'), 'name')) ?: '–';
        $people = implode(', ', array_column(array_filter($all, fn ($p) => $p['kind'] === 'person'), 'name')) ?: '–';
        $voiceRule = $voice === 'wir'
            ? 'Perspektive: „wir/unser“ – sprich für das Sonepar-Team bzw. die Niederlassung (z. B. „Wir als Sonepar …“, „unsere Kunden“). Kein „ich“.'
            : 'Perspektive: Ich-Form für eigene Eindrücke („Mein Highlight …“, „Ich freue mich …“); „wir/unser“ nur, wenn das Sonepar-Team gemeint ist.';
        $exampleText = '';
        foreach ($examples as $i => $ex) {
            $exampleText .= "\n<beispiel nr=\"" . ($i + 1) . "\">\n" . trim((string) $ex) . "\n</beispiel>";
        }

        return <<<TXT
        Du schreibst LinkedIn-Beiträge, die direkt von einer Fachmesse aus gepostet werden.

        Stilvorgabe der Vorlage „{$template['name']}“:
        {$template['style_prompt']}

        Regeln:
        - Sprache: Deutsch, persönliches LinkedIn-Profil.
        - {$voiceRule}
        - Die erste Zeile (hook) muss im Feed neugierig machen; LinkedIn zeigt nur ca. 200 Zeichen vor „…mehr“.
        - Body: kurze Absätze (1–3 Sätze), gut auf dem Handy lesbar. Emojis sparsam (höchstens 3), keine Aufzählungs-Wüsten.
        - Gesamtlänge hook + body: ideal 600–1300 Zeichen, niemals über 2700.
        - Hashtags gehören NICHT in body, sondern in die Felder hashtags (3–6 Empfehlungen) und hashtag_suggestions (5–8 weitere Ideen); ohne #, CamelCase bei Wortgruppen (z. B. TrendUndTechnik), Thema + Ort + Branche.
        - Verwende nur Fakten aus den Stichpunkten und dem, was auf den Bildern eindeutig zu sehen ist. Erfinde keine Zahlen, Namen, Produktdaten oder Zitate.
        - Personen und Firmen nur nennen, wenn sie in den Stichpunkten stehen.
        - Erwähnungen schreibst du im Format @[Name] mit exakt dem Namen aus diesen Listen – Firmen: {$orgs}; Personen: {$people}. Personen nur, wenn sie in den Stichpunkten vorkommen. Alle anderen Firmen und Personen als normalen Text, ohne @.
        - Links (URLs) aus den Stichpunkten unverändert übernehmen.
        - Beim Überarbeiten bekommst du den Text samt Hashtag-Zeile; liefere Text und Hashtags wieder getrennt.
        - Übernimm aus den Beispielen Tonalität, Aufbau und typische Formulierungen, kopiere sie aber nicht.
        TXT
        . ($exampleText !== '' ? "\n\nBeispielbeiträge für den Stil:" . $exampleText : '');
    }

    private static function fake(string $titlePoints, string $bodyPoints, string $instruction): array
    {
        $title = trim(strtok($titlePoints, "\n") ?: 'Messetag');
        $lines = array_filter(array_map('trim', explode("\n", $bodyPoints)));
        $body = "Was für ein Tag mit @[Sonepar Deutschland GmbH]! " . implode(' ', array_map(fn ($l) => rtrim(ltrim($l, '-• '), '.') . '.', $lines))
            . "\n\nDanke an alle, die vorbeigeschaut haben. Wer noch nicht da war: Kommt gerne an den Stand!"
            . ($instruction !== '' ? "\n\n(Überarbeitet: $instruction)" : '');
        return ['hook' => "$title 💡", 'body' => $body, 'hashtags' => ['Sonepar', 'Elektrohandwerk', 'Messe'], 'hashtag_suggestions' => ['teamsonepar', 'Elektrobranche', 'Innovation', 'Networking']];
    }
}
