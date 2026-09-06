<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/config.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$canEdit) {
        $error = $maxEditsReached
            ? 'Sorry, max edits reached.'
            : 'This post can no longer be edited.';
    } else {
        $content = trim($_POST['content'] ?? '');
        $hasImage = !empty($_FILES['image']['name']);
        $imagePath = $post['image_path'];

        if ($content === '' && !$hasImage && empty($post['image_path'])) {
            $error = 'Please write something or add an image.';
        } elseif ($content !== '' && postCharacterCount($content) > (int)$maxPostLength) {
            $error = 'Post is too long.';
        } elseif ($hasImage) {
            $uploadError = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($uploadError !== UPLOAD_ERR_OK) {
                $error = match ($uploadError) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image is too large. Maximum size is 5 MB.',
                    UPLOAD_ERR_PARTIAL => 'Image upload was incomplete. Please try again.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Image upload failed. Please try again later.',
                    UPLOAD_ERR_CANT_WRITE => 'Image could not be saved. Please try again later.',
                    UPLOAD_ERR_EXTENSION => 'Image upload was blocked by the server.',
                    default => 'Image upload failed. Please try again.',
                };
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $error = 'Image is too large. Maximum size is 5 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['image']['tmp_name']);
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

                if (!in_array($mime, $allowedMimes, true)) {
                    $error = 'Please upload a JPEG, PNG, or WebP image.';
                } elseif (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
                    $error = 'Image processing is unavailable. Please try again later.';
                } else {
                    $image = imagecreatefromstring(file_get_contents($_FILES['image']['tmp_name']));

                    if ($image === false) {
                        $error = "We couldn't process that image. Please try another one.";
                    } else {
                        imagepalettetotruecolor($image);
                        imagealphablending($image, false);
                        imagesavealpha($image, true);

                        $uploadDir = __DIR__ . '/uploads/posts';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                            imagedestroy($image);
                            $error = 'Image could not be saved. Please try again later.';
                        } else {
                            $filename = bin2hex(random_bytes(16)) . '.webp';
                            $destination = $uploadDir . '/' . $filename;

                            if (!imagewebp($image, $destination, 82)) {
                                imagedestroy($image);
                                @unlink($destination);
                                $error = 'Image could not be saved. Please try again later.';
                            } else {
                                imagedestroy($image);
                                $imagePath = 'uploads/posts/' . $filename;
                            }
                        }
                    }
                }
            }
        }

        if ($error === '') {
            $stmt = db()->prepare(
                'UPDATE posts SET content = ?, image_path = ?, updated_at = CURRENT_TIMESTAMP, edit_count = edit_count + 1 WHERE id = ? AND user_id = ? AND edit_count < ?'
            );
            $stmt->execute([$content, $imagePath, $postId, $user['id'], (int)$postEditCount]);

            if ($imagePath !== $post['image_path'] && $post['image_path'] && strncmp($post['image_path'], 'uploads/posts/', 14) === 0) {
                @unlink(__DIR__ . '/' . $post['image_path']);
            }

            header('Location: ' . $redirectTarget);
            exit;
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
    <div class="wrap">
        <?php if ($canEdit): ?>
            <form class="composer" method="post" action="edit.php" enctype="multipart/form-data" data-max-post-length="<?= (int)$maxPostLength ?>">
                <?php if ($error): ?>
                    <p class="form-error"><?= e($error) ?></p>
                <?php endif; ?>
                <textarea name="content" placeholder="What's happening?"><?= e($_POST['content'] ?? $post['content']) ?></textarea>
                <input type="file" id="image-upload" name="image" accept="image/jpeg,image/png,image/webp" hidden>

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
                        <span id="selected-image"></span>
                        <span id="char-count">0/<?= (int)$maxPostLength ?></span>
                        <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <a href="<?= e($redirectTarget) ?>">Cancel</a>
                        <button class="button" type="submit">Save</button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <p class="error"><?= e($maxEditsReached ? 'Sorry, max edits reached.' : 'This post can no longer be edited.') ?></p>
        <?php endif; ?>
    </div>
</main>

<footer>
    <div class="wrap">
        <span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
        <nav><a href="#">About</a><a href="#">Privacy</a><a href="#">Terms</a></nav>
    </div>
</footer>
<script type="module" src="assets/js/app.js"></script>
</body>
</html>
