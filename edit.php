<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/image.php';

$user = require_login();
$postId = (int)($_GET['post_id'] ?? $_POST['post_id'] ?? 0);
$redirect = ($_POST['redirect'] ?? $_GET['redirect'] ?? '') === 'profile' ? 'profile' : 'index';
$redirectTarget = $redirect === 'profile'
    ? 'profile.php?u=' . urlencode($user['username'])
    : 'index.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $target = 'home.php?edit=' . $postId;
    if ($redirect === 'profile') $target .= '&redirect=profile';
    header('Location: ' . $target);
    exit;
}

$stmt = db()->prepare('SELECT id, content, image_path, created_at, edit_count FROM posts WHERE id = ? AND user_id = ?');
$stmt->execute([$postId, $user['id']]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: index.php');
    exit;
}

$age = time() - strtotime($post['created_at']);
$canEdit = $age >= 0 && $age <= ((int)$postEditTime * 60) && (int)$post['edit_count'] < (int)$postEditCount;
$maxEditsReached = (int)$post['edit_count'] >= (int)$postEditCount;
$existingImages = getPostImages($post);

$error = '';
if (!$canEdit) {
    $error = $maxEditsReached ? 'Sorry, max edits reached.' : 'This post can no longer be edited.';
} else {
    $content = trim($_POST['content'] ?? '');
    $imageUrls = json_decode($_POST['image_urls'] ?? '[]', true);
    $imageOrder = json_decode($_POST['image_order'] ?? '[]', true);
    $imageUrls = is_array($imageUrls) ? array_values(array_filter($imageUrls, 'is_string')) : [];
    $imageOrder = is_array($imageOrder) ? array_values(array_filter($imageOrder, 'is_string')) : [];
    $files = $_FILES['images'] ?? null;
    $fileCount = is_array($files) && isset($files['name']) && is_array($files['name']) ? count($files['name']) : 0;
    $existingById = [];
    foreach ($existingImages as $image) {
        if ($image['id'] !== null) $existingById[(int)$image['id']] = $image['image_path'];
    }

    if (count($imageOrder) > MAX_POST_IMAGES) {
        $error = 'You can add up to ' . MAX_POST_IMAGES . ' images per post.';
    } elseif ($content !== '' && postCharacterCount($content) > (int)$maxPostLength) {
        $error = 'Post is too long.';
    }

    $newImages = [];
    $finalImages = [];
    $retainedPaths = [];
    $seen = [];

    if ($error === '') {
        try {
            foreach ($imageOrder as $token) {
                if (isset($seen[$token])) throw new RuntimeException('Invalid image selection.');
                $seen[$token] = true;

                if (preg_match('/^existing:(\d+)$/', $token, $match)) {
                    $imageId = (int)$match[1];
                    if (!array_key_exists($imageId, $existingById)) throw new RuntimeException('Invalid image selection.');
                    $path = $existingById[$imageId];
                    $finalImages[] = $path;
                    $retainedPaths[] = $path;
                } elseif (preg_match('/^file:(\d+)$/', $token, $match)) {
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
                    $path = saveImageFromFile($files['tmp_name'][$index]);
                    $newImages[] = $path;
                    $finalImages[] = $path;
                } elseif (preg_match('/^url:(\d+)$/', $token, $match)) {
                    $index = (int)$match[1];
                    if (!array_key_exists($index, $imageUrls) || trim($imageUrls[$index]) === '') throw new RuntimeException('Invalid image selection.');
                    $path = saveImageFromUrl(trim($imageUrls[$index]));
                    $newImages[] = $path;
                    $finalImages[] = $path;
                } else {
                    throw new RuntimeException('Invalid image selection.');
                }
            }

            if ($content === '' && !$finalImages) {
                throw new RuntimeException('Please write something or add an image.');
            }
        } catch (RuntimeException $e) {
            foreach ($newImages as $path) @unlink(__DIR__ . '/' . $path);
            $newImages = [];
            $error = $e->getMessage();
        }
    }

    if ($error === '') {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE posts SET content = ?, image_path = NULL, updated_at = CURRENT_TIMESTAMP, edit_count = edit_count + 1 WHERE id = ? AND user_id = ? AND edit_count < ?'
            );
            $stmt->execute([$content, $postId, $user['id'], (int)$postEditCount]);

            $pdo->prepare('DELETE FROM post_images WHERE post_id = ?')->execute([$postId]);
            $imageStmt = $pdo->prepare('INSERT INTO post_images (post_id, image_path, position) VALUES (?, ?, ?)');
            foreach ($finalImages as $position => $path) {
                $imageStmt->execute([$postId, $path, $position]);
            }

            $pdo->commit();

            foreach ($existingById as $path) {
                if (!in_array($path, $retainedPaths, true) && strncmp($path, 'uploads/posts/', 14) === 0) {
                    @unlink(__DIR__ . '/' . $path);
                }
            }

            header('Location: ' . $redirectTarget);
            exit;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            foreach ($newImages as $path) @unlink(__DIR__ . '/' . $path);
            $error = 'Could not save the post. Please try again.';
        }
    }
}

set_flash('edit_error', $error);
set_flash('edit_draft', $_POST['content'] ?? $post['content']);
$target = 'home.php?edit=' . $postId;
if ($redirect === 'profile') $target .= '&redirect=profile';
header('Location: ' . $target);
exit;
