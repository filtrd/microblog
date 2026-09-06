<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/config.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verify_csrf();

$postId = (int)($_POST['post_id'] ?? 0);

$stmt = db()->prepare(
    'SELECT p.created_at, u.username
     FROM posts p
     JOIN users u ON u.id = p.user_id
     WHERE p.id = ? AND p.user_id = ?'
);
$stmt->execute([$postId, $user['id']]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: index.php');
    exit;
}

$age = time() - strtotime($post['created_at']);

if ($age < 0 || $age > ((int)$postDeleteTime * 60)) {
    $target = ($_POST['redirect'] ?? '') === 'profile'
        ? 'profile.php?u=' . urlencode($post['username'])
        : 'index.php';
    header('Location: ' . $target);
    exit;
}

$stmt = db()->prepare('SELECT image_path FROM post_images WHERE post_id = ?');
$stmt->execute([$postId]);
$imagePaths = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($imagePaths as $imagePath) {
    if (strncmp($imagePath, 'uploads/posts/', 14) === 0) {
        @unlink(__DIR__ . '/' . $imagePath);
    }
}

$stmt = db()->prepare('DELETE FROM posts WHERE id = ? AND user_id = ?');
$stmt->execute([$postId, $user['id']]);

if (($_POST['redirect'] ?? '') === 'profile') {
    header('Location: profile.php?u=' . urlencode($post['username']));
} else {
    header('Location: index.php');
}
exit;
