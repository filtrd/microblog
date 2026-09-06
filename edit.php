<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/image.php';

$user = require_login();
$postId = (int)($_GET['post_id'] ?? $_POST['post_id'] ?? 0);

$stmt = db()->prepare('SELECT id, content, image_path, created_at, edit_count FROM posts WHERE id = ? AND user_id = ?');
$stmt->execute([$postId, $user['id']]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: index.php');
    exit;
}

$age = time() - strtotime($post['created_at']);
$canEdit = $age >= 0 && $age <= ((int)$postEditTime * 60) && (int)$post['edit_count'] < (int)$postEditCount;
$redirect = ($_POST['redirect'] ?? $_GET['redirect'] ?? '') === 'profile' ? 'profile' : 'index';
$redirectTarget = $redirect === 'profile'
    ? 'profile.php?u=' . urlencode($user['username'])
    : 'index.php';
$error = '';
$maxEditsReached = (int)$post['edit_count'] >= (int)$postEditCount;
$remainingEdits = max(0, (int)$postEditCount - (int)$post['edit_count']);
$remainingMinutes = max(0, (int)ceil(((int)$postEditTime * 60 - max(0, $age)) / 60));
$existingImages = getPostImages($post);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$canEdit) {
        $error = $maxEditsReached
            ? 'Sorry, max edits reached.'
            : 'This post can no longer be edited.';
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
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($maxEditsReached): ?>
<meta http-equiv="refresh" content="5;url=<?= e($redirectTarget) ?>">
<?php endif; ?>
<title>Edit post · <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/media.css">
<link rel="stylesheet" href="assets/composer.css">
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <div class="brand">
            <a class="logo" href="index.php"><?= e($siteName) ?></a>
            <p class="tagline"><?= e($tagLine) ?></p>
        </div>
        <nav>
            <a href="home.php">Home</a>
            <a class="profile-link" href="profile.php?u=<?= urlencode($user['username']) ?>">@<?= e($user['username']) ?></a>
            <form class="inline" method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button>Log out</button>
            </form>
        </nav>
    </div>
</header>

<main>
    <div class="wrap"></div>
</main>

<?php if ($canEdit): ?>
<dialog class="composer-dialog" aria-labelledby="composer-dialog-title" data-open-on-load="1" data-close-url="<?= e($redirectTarget) ?>">
    <div class="composer-dialog-head">
        <strong id="composer-dialog-title">Edit post</strong>
        <button type="button" id="composer-close">Close</button>
    </div>
    <p class="edit-info">You can edit this post <?= $remainingEdits ?> more time<?= $remainingEdits === 1 ? '' : 's' ?> within <?= $remainingMinutes ?> minute<?= $remainingMinutes === 1 ? '' : 's' ?>.</p>
    <form class="composer" method="post" action="edit.php" enctype="multipart/form-data" data-max-post-length="<?= (int)$maxPostLength ?>">
        <?php if ($error): ?>
            <p class="form-error"><?= e($error) ?></p>
        <?php endif; ?>
        <textarea name="content" placeholder="What's happening?"><?= e($_POST['content'] ?? $post['content']) ?></textarea>
        <input type="file" id="image-upload" name="images[]" accept="image/jpeg,image/png,image/webp" multiple hidden>
        <input type="hidden" id="image-urls" name="image_urls" value="[]">
        <input type="hidden" id="image-order" name="image_order" value="<?= e(json_encode(array_map(fn($image) => 'existing:' . (int)$image['id'], array_filter($existingImages, fn($image) => $image['id'] !== null)), JSON_THROW_ON_ERROR)) ?>">

        <div id="selected-images" class="selected-images" aria-label="Selected images">
            <?php foreach ($existingImages as $index => $image): ?>
                <?php if ($image['id'] !== null): ?>
                    <div class="selected-image" data-image-type="existing" data-image-id="<?= (int)$image['id'] ?>" data-image-src="<?= e($image['image_path']) ?>">
                        <img src="<?= e($image['image_path']) ?>" alt="">
                        <div class="selected-image-actions">
                            <button type="button" data-image-move="left" aria-label="Move image left" <?= $index === 0 ? 'disabled' : '' ?>>‹</button>
                            <button type="button" data-image-remove aria-label="Remove image">×</button>
                            <button type="button" data-image-move="right" aria-label="Move image right">›</button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="composer-tools">
            <div class="composer-shortcuts">
                <button type="button" class="icon-button" id="image-button" aria-label="Add image" title="Add image">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="1"></rect><circle cx="8" cy="9" r="1.5"></circle><path d="M4 17l5-5 3.5 3.5 2.5-2.5 5 5"></path></svg>
                </button>
                <button type="button" class="icon-button" id="emoji-button" aria-label="Add emoji" title="Add emoji">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><circle cx="9" cy="10" r="1"></circle><circle cx="15" cy="10" r="1"></circle><path d="M8.5 14.5c1 1.5 2.2 2.25 3.5 2.25s2.5-.75 3.5-2.25"></path></svg>
                </button>
                <div class="emoji-picker" id="emoji-picker" hidden>
                    <button type="button">😀</button><button type="button">😂</button><button type="button">❤️</button><button type="button">👍</button><button type="button">🎉</button><button type="button">🔥</button><button type="button">🚀</button><button type="button">😊</button><button type="button">😎</button><button type="button">🤔</button><button type="button">👏</button><button type="button">🙌</button>
                </div>
            </div>
            <div class="composer-meta">
                <span id="image-count"></span>
                <span id="char-count">0/<?= (int)$maxPostLength ?></span>
                <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <a href="<?= e($redirectTarget) ?>">Cancel</a>
                <button class="button" type="submit">Save</button>
            </div>
        </div>
    </form>
</dialog>
<?php endif; ?>

<dialog id="image-dialog" aria-labelledby="image-dialog-title">
    <p id="image-dialog-title">Add image</p>
    <div class="image-dialog-options">
        <button type="button" id="upload-image-option">Upload image</button>
        <button type="button" id="url-image-option">From URL</button>
        <button type="button" id="image-dialog-cancel">Cancel</button>
    </div>
    <div id="image-url-form" hidden>
        <input type="url" id="image-url-input" placeholder="https://example.com/image.jpg" autocomplete="url">
        <div class="image-dialog-actions">
            <button type="button" id="image-url-cancel">Cancel</button>
            <button type="button" id="image-url-add">Add image</button>
        </div>
    </div>
</dialog>

<footer>
    <div class="wrap">
        <span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
        <nav><a href="#">About</a><a href="#">Privacy</a><a href="#">Terms</a></nav>
    </div>
</footer>
<script type="module" src="assets/js/app.js"></script>
</body>
</html>
