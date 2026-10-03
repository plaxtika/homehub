# Messe-Poster (LinkedIn-PWA)

PWA, mit der man auf Messen in wenigen Minuten LinkedIn-Beiträge vom Handy aus erstellt und postet. Läuft unter `plaxtika.de/linkedin`.

- **Fotos:** aus Kamera oder Galerie, zuschneiden (frei, 1:1, 4:5, quer), drehen, per Drag & Drop sortieren (max. 20)
- **Stichpunkte:** für Überschrift und Text, Vorlage wählen, Perspektive **Ich** oder **Wir/unser** → Claude schreibt den Beitrag im Sonepar-Stil (Vorlagen mit echten Beispielposts)
- **Text:** frei bearbeitbar, „Überarbeiten“ mit Anweisung (z. B. „kürzer“)
- **Hashtags:** Claude schlägt sie vor, du entfernst, ergänzt oder wählst weitere aus den Vorschlägen; Standard-Hashtags sind einstellbar
- **@-Verknüpfungen:** Firmen (dokumentierte API) und Personen (experimentell, mit Absicherung)
- **Vorschau:** Feed-Karte im LinkedIn-Look mit „…mehr“, darunter der Volltext
- **Veröffentlichen:** sofort oder zeitversetzt (Cron auf dem Server); Testposts nur für Kontakte, Löschen aus der App
- **Offline:** Entwürfe und Fotos bleiben auf dem Handy und werden synchronisiert, sobald wieder Netz da ist

**Technik:** PHP 8.1+ mit SQLite, Vanilla-JS ohne Build-Schritt, Cropper.js, SortableJS, Anthropic PHP SDK, LinkedIn Posts API.

**Einrichtung:** siehe [docs/SETUP.md](docs/SETUP.md).

**Lokal testen:**
```bash
cd web && composer install
cp config.sample.php config.php   # 'dry_run' => true, app_secret setzen, base_url http://localhost:8088
php -S localhost:8088
```
