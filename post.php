<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/image.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verify_csrf();

$content = trim($_POST['content'] ?? '');
$hasUpload = !empty($_FILES['image']['name']);
$imageUrl = trim($_POST['image_url'] ?? '');
$hasImage = $hasUpload || $imageUrl !== '';

if ($content === '' && !$hasImage) {
    set_flash('post_error', 'Please write something or add an image.');
    set_flash('post_draft', $content);
    header('Location: index.php');
    exit;
}

if ($content !== '' && postCharacterCount($content) > (int)$maxPostLength) {
    set_flash('post_error', 'Your post is too long. Maximum length is ' . (int)$maxPostLength . ' characters.');
    set_flash('post_draft', $content);
    header('Location: index.php');
    exit;
}

$imagePath = null;

try {
    if ($hasUpload) {
        $uploadError = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image is too large. Maximum size is 5 MB.',
                UPLOAD_ERR_PARTIAL => 'Image upload was incomplete. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Image upload failed. Please try again later.',
                UPLOAD_ERR_CANT_WRITE => 'Image could not be saved. Please try again later.',
                UPLOAD_ERR_EXTENSION => 'Image upload was blocked by the server.',
                default => 'Image upload failed. Please try again.',
            });
        }
        $imagePath = saveImageFromFile($_FILES['image']['tmp_name']);
    } elseif ($imageUrl !== '') {
        $imagePath = saveImageFromUrl($imageUrl);
    }
} catch (RuntimeException $e) {
    set_flash('post_error', $e->getMessage());
    set_flash('post_draft', $content);
    header('Location: index.php');
    exit;
}

$stmt = db()->prepare(
    'INSERT INTO posts (user_id, content, image_path) VALUES (?, ?, ?)'
);

$stmt->execute([
    $user['id'],
    $content,
    $imagePath
]);

header('Location: index.php');
