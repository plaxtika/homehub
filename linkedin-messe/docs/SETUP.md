# Einrichtung: Messe-Poster unter plaxtika.de/linkedin

Einmalig etwa 30–45 Minuten. Die Reihenfolge ist wichtig.

## 1. LinkedIn-Developer-App anlegen

1. Öffne <https://www.linkedin.com/developers/apps> und klicke auf **Create app**.
2. **App name:** z. B. „Messe-Poster“.
3. **LinkedIn Page:** Hier muss eine Unternehmensseite verknüpft werden, das verlangt LinkedIn. Das kann eine eigene kleine Seite sein. Auf die Sonepar-Seite hast du vermutlich keinen Admin-Zugriff, und den brauchst du dafür auch nicht.
4. Lade ein Logo hoch und akzeptiere die Bedingungen.
5. Füge im Tab **Products** zwei Produkte hinzu:
   - **Sign In with LinkedIn using OpenID Connect**
   - **Share on LinkedIn**

   Beide werden meist sofort freigeschaltet.
6. Im Tab **Auth**:
   - Notiere **Client ID** und **Primary Client Secret**.
   - Unter **Authorized redirect URLs** trägst du genau diese Adresse ein: `https://plaxtika.de/linkedin/oauth.php`
   - Prüfe unter **OAuth 2.0 scopes**, dass `openid`, `profile` und `w_member_social` aufgeführt sind.

## 2. GitHub-Secrets für den Deploy (SSH)

Repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Inhalt |
|---|---|
| `SSH_HOST` | SSH-Host deines Hosters (z. B. `ssh.plaxtika.de` oder der Servername) |
| `SSH_USER` | SSH-Benutzer |
| `SSH_PRIVATE_KEY` | Privater Schlüssel, am besten ein eigener Deploy-Key (`ssh-keygen -t ed25519 -f deploy_key`). Den öffentlichen Teil trägst du beim Hoster unter „SSH-Keys“ ein. |
| `DEPLOY_PATH` | Absoluter Pfad des Zielordners, z. B. `/www/htdocs/w0123456/plaxtika.de/linkedin` |
| `SSH_KNOWN_HOSTS` *(optional, empfohlen)* | Ausgabe von `ssh-keyscan <host>`. Fehlt das Secret, liest der Workflow den Schlüssel bei jedem Lauf selbst ein. |

Falls dein Hoster einen anderen SSH-Port als 22 nutzt, lege unter **Variables** zusätzlich `SSH_PORT` an.

Der Deploy startet automatisch bei jedem Push auf `master`, der `linkedin-messe/` ändert. Von Hand startest du ihn über **Actions → Deploy LinkedIn-Messe-PWA → Run workflow**.

## 3. config.php auf dem Server anlegen

Nach dem ersten Deploy:

1. Kopiere auf dem Server `linkedin/config.sample.php` nach `linkedin/config.php`.
2. Trage folgende Werte ein:
   - `app_secret`: einen langen Zufallswert, erzeugt mit `php -r "echo bin2hex(random_bytes(32));"`
   - `linkedin_client_id` und `linkedin_client_secret` aus Schritt 1
   - `anthropic_api_key`
   - `allowed_sub` erst einmal **leer lassen**
3. Öffne `https://plaxtika.de/linkedin` und melde dich mit LinkedIn an. Die App zeigt dir dann deine LinkedIn-ID an.
4. Trage diese ID bei `allowed_sub` ein und melde dich erneut an. Ab dann kommt **nur dein Konto** in die App.

Der Deploy überschreibt `config.php` und den Ordner `data/` nie. Dort liegen die Datenbank und die Fotos.

**Voraussetzungen beim Hoster:**
- PHP 8.1 oder neuer mit den Erweiterungen `pdo_sqlite`, `curl`, `openssl` und `gd`. `gd` ist optional und wird nur für verkleinerte Bilder an Claude gebraucht.
- HTTPS
- `mod_rewrite`

## 4. Cronjob für zeitversetztes Posten

Lege im Hosting-Panel einen Cronjob an, **alle 5 Minuten**. Dafür gibt es zwei Varianten:

- **Per PHP-CLI** (bevorzugt): `php /pfad/zu/linkedin/cron.php`
- **Per URL**, wenn dein Hoster nur URLs aufrufen kann:
  1. Ermittle per SSH einmal den Schlüssel: `php cron.php --key`
  2. Trage dann diese URL ein: `https://plaxtika.de/linkedin/cron.php?key=<Schlüssel>`

## 5. Erster Test

1. Öffne die App auf dem Handy und wähle im Browser-Menü **„Zum Startbildschirm hinzufügen“**.
2. Lege einen Beitrag an. Wähle in der Vorschau bei Sichtbarkeit **„Nur Kontakte“** und dann **Jetzt veröffentlichen**.
3. Prüfe den Beitrag auf LinkedIn. Achte vor allem darauf, ob Hashtags und Firmen-Verknüpfungen klickbar sind.
4. Lösche den Testbeitrag mit **Von LinkedIn löschen**.
5. Plane einen zweiten Beitrag für 10 Minuten später und prüfe, ob er erscheint.

## Gut zu wissen

- **Login-Ablauf:** Das LinkedIn-Token gilt etwa 60 Tage. Die App warnt 7 Tage vorher. Melde dich **vor jeder Messe einmal neu an**, sonst schlagen geplante Beiträge fehl.
- **Verknüpfungen (@):** Unter „Vorlagen“ legst du Firmen und Personen mit ihrer LinkedIn-ID an. Wie du die ID findest, steht in der App.
  - Bei Firmen ist das Verfahren dokumentiert.
  - Bei Personen ist es experimentell. Lehnt LinkedIn die Verknüpfung ab, wird der Name als normaler Text gepostet und die App zeigt einen Hinweis.
- **API-Version:** `linkedin_version` in `config.php` (Format JJJJMM) läuft nach etwa 12 Monaten aus. Wenn das Posten plötzlich mit „version“ im Fehlertext scheitert, trägst du dort einen aktuelleren Monat ein.
- **Testmodus:** Mit `'dry_run' => true` wird nichts an LinkedIn gesendet. Ohne API-Key ist auch Claude simuliert.
- **Kosten Claude:** Bei `claude-opus-5-5` kostet ein generierter Beitrag mit Bildern grob 3–8 Cent (geschätzt aus Listenpreis, inkl. bis zu 4 Bildern). Günstiger geht es mit `'claude_model' => 'claude-sonnet-5-5'`.
