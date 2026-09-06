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
$imageUrls = json_decode($_POST['image_urls'] ?? '[]', true);
$imageOrder = json_decode($_POST['image_order'] ?? '[]', true);
$imageUrls = is_array($imageUrls) ? array_values(array_filter($imageUrls, 'is_string')) : [];
$imageOrder = is_array($imageOrder) ? array_values(array_filter($imageOrder, 'is_string')) : [];
$files = $_FILES['images'] ?? null;
$fileCount = is_array($files) && isset($files['name']) && is_array($files['name']) ? count($files['name']) : 0;

if (count($imageOrder) > MAX_POST_IMAGES) {
    set_flash('post_error', 'You can add up to ' . MAX_POST_IMAGES . ' images per post.');
    set_flash('post_draft', $content);
    header('Location: home.php');
    exit;
}

if ($content === '' && !$imageOrder) {
    set_flash('post_error', 'Please write something or add an image.');
    set_flash('post_draft', $content);
    header('Location: home.php');
    exit;
}

if ($content !== '' && postCharacterCount($content) > (int)$maxPostLength) {
    set_flash('post_error', 'Your post is too long. Maximum length is ' . (int)$maxPostLength . ' characters.');
    set_flash('post_draft', $content);
    header('Location: home.php');
    exit;
}

$newImages = [];
$seen = [];

try {
    foreach ($imageOrder as $token) {
        if (isset($seen[$token])) throw new RuntimeException('Invalid image selection.');
        $seen[$token] = true;

        if (preg_match('/^file:(\d+)$/', $token, $match)) {
            $index = (int)$match[1];
            if ($index < 0 || $index >= $fileCount) throw new RuntimeException('Invalid image selection.');

            $uploadError = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
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

            $newImages[] = saveImageFromFile($files['tmp_name'][$index]);
        } elseif (preg_match('/^url:(\d+)$/', $token, $match)) {
            $index = (int)$match[1];
            if (!array_key_exists($index, $imageUrls) || trim($imageUrls[$index]) === '') throw new RuntimeException('Invalid image selection.');
            $newImages[] = saveImageFromUrl(trim($imageUrls[$index]));
        } else {
            throw new RuntimeException('Invalid image selection.');
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO posts (user_id, content, image_path) VALUES (?, ?, NULL)');
    $stmt->execute([$user['id'], $content]);
    $postId = (int)$pdo->lastInsertId();

    $imageStmt = $pdo->prepare('INSERT INTO post_images (post_id, image_path, position) VALUES (?, ?, ?)');
    foreach ($newImages as $position => $imagePath) {
        $imageStmt->execute([$postId, $imagePath, $position]);
    }
    $pdo->commit();
} catch (RuntimeException $e) {
    if (db()->inTransaction()) db()->rollBack();
    foreach ($newImages as $imagePath) {
        @unlink(__DIR__ . '/' . $imagePath);
    }
    set_flash('post_error', $e->getMessage());
    set_flash('post_draft', $content);
    header('Location: home.php');
    exit;
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    foreach ($newImages as $imagePath) {
        @unlink(__DIR__ . '/' . $imagePath);
    }
    set_flash('post_error', 'Could not save the post. Please try again.');
    set_flash('post_draft', $content);
    header('Location: home.php');
    exit;
}

header('Location: home.php');
