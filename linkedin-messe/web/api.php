<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($action === 'me') {
        start_session();
        if (!Auth::isLoggedIn()) {
            json_out(['loggedIn' => false, 'pendingSub' => $_SESSION['pending_sub'] ?? null]);
        }
        $profile = Auth::profile();
        json_out([
            'loggedIn' => true,
            'csrf' => Auth::csrfToken(),
            'name' => $profile['name'],
            'picture' => $profile['picture'],
            'tokenExpires' => $profile['expires'],
            'dryRun' => (bool) cfg('dry_run'),
            'limits' => ['chars' => Posts::MAX_CHARS, 'images' => Posts::MAX_IMAGES],
            'defaultHashtags' => json_decode((string) Db::setting('default_hashtags', '[]'), true) ?: [],
        ]);
    }

    Auth::requireLogin();
    if ($method !== 'GET') {
        Auth::checkCsrf();
    }
    $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $id = (string) ($_GET['id'] ?? '');

    switch ("$method $action") {
        case 'GET posts':
            json_out(Posts::list());
        case 'GET post':
            json_out(Posts::get($id) ?? throw new InvalidArgumentException('Beitrag nicht gefunden.'));
        case 'PUT post':
            json_out(Posts::save($id, $body));
        case 'DELETE post':
            Posts::delete($id);
            json_out(['ok' => true]);

        case 'POST image':
            if (!Db::one('SELECT 1 FROM posts WHERE id = ?', [$id])) {
                throw new InvalidArgumentException('Beitrag erst speichern.');
            }
            if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload fehlgeschlagen (Code ' . ($_FILES['file']['error'] ?? '?') . ').');
            }
            json_out(Images::store($id, (string) ($_POST['image_id'] ?? ''), $_FILES['file']['tmp_name'], (int) ($_POST['position'] ?? 0)));

        case 'POST generate':
            $post = Posts::get($id) ?? throw new InvalidArgumentException('Beitrag nicht gefunden.');
            $template = Db::one('SELECT * FROM templates WHERE id = ?', [(int) ($body['template_id'] ?? $post['template_id'])])
                ?? Db::one('SELECT * FROM templates ORDER BY sort LIMIT 1');
            $paths = !empty($body['with_images'])
                ? array_map(fn ($i) => Images::path($i['filename']), Images::forPost($id))
                : [];
            json_out(Claude::generate($template, (string) ($body['voice'] ?? $post['voice']), $post['title_points'], $post['body_points'], $paths,
                (string) ($body['current_text'] ?? ''), (string) ($body['instruction'] ?? '')));

        case 'POST publish':
            json_out(Posts::publishNow($id));
        case 'POST schedule':
            json_out(Posts::schedule($id, (int) ($body['at'] ?? 0)));
        case 'POST unschedule':
            json_out(Posts::unschedule($id));
        case 'POST unpublish':
            json_out(Posts::deleteOnLinkedIn($id));

        case 'GET templates':
            json_out(array_map(function ($t) {
                $t['examples'] = json_decode($t['examples'], true) ?: [];
                $t['id'] = (int) $t['id'];
                $t['provisional'] = (bool) $t['provisional'];
                return $t;
            }, Db::all('SELECT * FROM templates ORDER BY sort, id')));
        case 'PUT template':
            $examples = array_values(array_filter(array_map('trim', (array) ($body['examples'] ?? [])), 'strlen'));
            $values = [trim((string) ($body['name'] ?? '')) ?: 'Vorlage', (string) ($body['style_prompt'] ?? ''),
                json_encode($examples, JSON_UNESCAPED_UNICODE), empty($body['provisional']) ? 0 : 1];
            if ($id !== '' && $id !== 'new') {
                Db::exec('UPDATE templates SET name = ?, style_prompt = ?, examples = ?, provisional = ? WHERE id = ?', [...$values, (int) $id]);
            } else {
                Db::exec('INSERT INTO templates (name, style_prompt, examples, provisional, sort) VALUES (?, ?, ?, ?, 99)', $values);
            }
            json_out(['ok' => true]);
        case 'PUT default_hashtags':
            Db::setSetting('default_hashtags', json_encode(Posts::cleanTags((array) ($body['tags'] ?? [])), JSON_UNESCAPED_UNICODE));
            json_out(['ok' => true]);

        case 'GET partners':
            json_out(Partners::all());
        case 'PUT partner':
            Partners::save($id !== '' && $id !== 'new' ? (int) $id : null, $body);
            json_out(['ok' => true]);
        case 'DELETE partner':
            Db::exec('DELETE FROM partners WHERE id = ?', [(int) $id]);
            json_out(['ok' => true]);

        case 'DELETE template':
            Db::exec('DELETE FROM templates WHERE id = ?', [(int) $id]);
            json_out(['ok' => true]);
    }
    json_out(['error' => 'Unbekannte Aktion'], 404);
} catch (InvalidArgumentException $e) {
    json_out(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[linkedin-messe] ' . $e);
    json_out(['error' => $e->getMessage()], 500);
}
