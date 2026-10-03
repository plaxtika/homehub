<?php
declare(strict_types=1);

final class Images
{
    public const MAX_BYTES = 12 * 1024 * 1024;

    public static function path(string $filename): string
    {
        return DATA_DIR . '/images/' . basename($filename);
    }

    /** Prüft und speichert ein hochgeladenes Bild (im Browser bereits zugeschnitten/gedreht). */
    public static function store(string $postId, string $imageId, string $tmpFile, int $position): array
    {
        if (!preg_match('/^[a-f0-9-]{8,40}$/', $imageId)) {
            throw new InvalidArgumentException('Ungültige Bild-ID');
        }
        if (filesize($tmpFile) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Bild ist zu groß (max. 12 MB).');
        }
        $info = @getimagesize($tmpFile);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new InvalidArgumentException('Nur JPEG oder PNG erlaubt.');
        }
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $filename = $imageId . '.' . $ext;
        if (!move_uploaded_file($tmpFile, self::path($filename)) && !rename($tmpFile, self::path($filename))) {
            throw new RuntimeException('Bild konnte nicht gespeichert werden.');
        }

        $old = Db::one('SELECT filename FROM images WHERE id = ?', [$imageId]);
        if ($old && $old['filename'] !== $filename) {
            @unlink(self::path($old['filename']));
        }
        Db::exec('INSERT INTO images (id, post_id, position, filename, width, height) VALUES (?, ?, ?, ?, ?, ?)
                  ON CONFLICT(id) DO UPDATE SET position = excluded.position, filename = excluded.filename,
                  width = excluded.width, height = excluded.height',
            [$imageId, $postId, $position, $filename, $info[0], $info[1]]);
        return ['id' => $imageId, 'width' => $info[0], 'height' => $info[1]];
    }

    public static function forPost(string $postId): array
    {
        return Db::all('SELECT id, position, filename, width, height, alt FROM images WHERE post_id = ? ORDER BY position', [$postId]);
    }

    /** Setzt Reihenfolge laut Liste und entfernt Bilder, die nicht mehr in der Liste stehen. */
    public static function sync(string $postId, array $orderedIds, array $alts = []): void
    {
        foreach (self::forPost($postId) as $img) {
            $pos = array_search($img['id'], $orderedIds, true);
            if ($pos === false) {
                @unlink(self::path($img['filename']));
                Db::exec('DELETE FROM images WHERE id = ?', [$img['id']]);
            } else {
                Db::exec('UPDATE images SET position = ?, alt = ? WHERE id = ?', [$pos, (string) ($alts[$img['id']] ?? $img['alt']), $img['id']]);
            }
        }
    }

    public static function deleteForPost(string $postId): void
    {
        foreach (self::forPost($postId) as $img) {
            @unlink(self::path($img['filename']));
        }
    }

    /** Verkleinert ein Bild für Claude (max. 1024 px) und liefert Base64-JPEG oder null. */
    public static function forVision(string $path): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return filesize($path) < 3_500_000 && str_ends_with($path, '.jpg') ? base64_encode(file_get_contents($path)) : null;
        }
        $src = @imagecreatefromstring(file_get_contents($path));
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, 1024 / max($w, $h));
        $dst = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        ob_start();
        imagejpeg($dst ?: $src, null, 80);
        return base64_encode((string) ob_get_clean());
    }
}
