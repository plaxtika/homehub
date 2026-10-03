<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    exit;
}
$img = Db::one('SELECT filename FROM images WHERE id = ?', [(string) ($_GET['id'] ?? '')]);
$path = $img ? Images::path($img['filename']) : null;
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=31536000, immutable');
readfile($path);
