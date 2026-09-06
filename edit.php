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

        if ($content === '' && empty($post['image_path'])) {
            $error = 'Post cannot be empty.';
        } elseif (postCharacterCount($content) > $maxPostLength) {
            $error = 'Post is too long.';
        } else {
            $stmt = db()->prepare(
                'UPDATE posts SET content = ?, updated_at = CURRENT_TIMESTAMP, edit_count = edit_count + 1 WHERE id = ? AND user_id = ? AND edit_count < ?'
            );
            $stmt->execute([$content, $postId, $user['id'], (int)$postEditCount]);

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
            <a href="./">Home</a>
            <a href="profile.php?u=<?= urlencode($user['username']) ?>">@<?= e($user['username']) ?></a>
            <form class="inline" method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button>Log out</button>
            </form>
        </nav>
    </div>
</header>

<main>
    <div class="wrap">
        <section class="edit-post">
            <?php if ($canEdit): ?>
                <h1>Edit post</h1>
                <form class="edit-post-form" method="post" data-max-post-length="<?= (int)$maxPostLength ?>">
                    <textarea name="content" maxlength="<?= (int)$maxPostLength ?>"><?= e($post['content']) ?></textarea>
                    <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <div class="edit-post-actions">
                        <span>You have <?= (int)$postEditCount - (int)$post['edit_count'] ?> edits and <?= max(0, (int)$postEditTime - (int)floor($age / 60)) ?> minutes left · <span id="edit-char-count">0/<?= (int)$maxPostLength ?></span></span>
                        <div>
                            <a href="<?= e($redirectTarget) ?>">Cancel</a>
                            <button class="button" type="submit">Save</button>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <p class="error"><?= e($maxEditsReached ? 'Sorry, max edits reached.' : 'This post can no longer be edited.') ?></p>
            <?php endif; ?>
        </section>
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
