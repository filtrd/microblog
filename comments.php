<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/config.php';

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = require_login();
    verify_csrf();

    $postId = (int)($_POST['post_id'] ?? 0);
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');

    if ($postId <= 0 || $content === '') {
        set_flash('comment_error', 'Comment cannot be empty.');
        header('Location: comments.php?post_id=' . $postId);
        exit;
    }

    if (postCharacterCount($content) > (int)$maxPostLength) {
        set_flash('comment_error', 'Comment is too long. Maximum length is ' . (int)$maxPostLength . ' characters.');
        header('Location: comments.php?post_id=' . $postId);
        exit;
    }

    $stmt = db()->prepare('SELECT id FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        exit('Post not found');
    }

    if ($parentId > 0) {
        $stmt = db()->prepare('SELECT id FROM comments WHERE id = ? AND post_id = ?');
        $stmt->execute([$parentId, $postId]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            exit('Invalid reply');
        }
    } else {
        $parentId = null;
    }

    $stmt = db()->prepare('INSERT INTO comments (post_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)');
    $stmt->execute([$postId, $user['id'], $parentId, $content]);

    $redirect = ($_POST['from'] ?? '') === 'profile' && trim($_POST['u'] ?? '') !== ''
        ? '&from=profile&u=' . urlencode(trim($_POST['u']))
        : '';
    header('Location: comments.php?post_id=' . $postId . $redirect);
    exit;
}

$postId = (int)($_GET['post_id'] ?? 0);
if ($postId <= 0) {
    header('Location: index.php');
    exit;
}

$fromProfile = ($_GET['from'] ?? '') === 'profile';
$profileUsername = trim($_GET['u'] ?? '');
$backUrl = $fromProfile && $profileUsername !== ''
    ? 'profile.php?u=' . urlencode($profileUsername)
    : 'index.php';

$stmt = db()->prepare('SELECT p.id, p.content, p.image_path, p.created_at, p.edit_count, u.id AS user_id, u.username, u.avatar_path, (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count, (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?');
$stmt->execute([$postId]);
$post = $stmt->fetch();
if (!$post) {
    http_response_code(404);
    exit('Post not found');
}

$stmt = db()->prepare('SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.created_at, u.username FROM comments c JOIN users u ON u.id = c.user_id WHERE c.post_id = ? ORDER BY c.created_at ASC, c.id ASC');
$stmt->execute([$postId]);
$commentRows = $stmt->fetchAll();
$comments = [];
foreach ($commentRows as $comment) {
    $comments[$comment['parent_id'] === null ? null : (int)$comment['parent_id']][] = $comment;
}

$commentError = get_flash('comment_error');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comments · <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/media.css">
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
            <?php if ($user): ?>
                <a href="profile.php?u=<?= urlencode($user['username']) ?>">@<?= e($user['username']) ?></a>
                <form class="inline" method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button>Log out</button></form>
            <?php else: ?>
                <a href="login.php">Log in</a><a class="button" href="register.php">Sign up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main>
    <div class="wrap">
        <p><a href="<?= e($backUrl) ?>">&larr; Back</a></p>

        <?php renderPost($post, $user, $fromProfile ? 'profile' : 'index'); ?>

        <section class="comments-page">
            <h1>Comments<?= $commentRows ? ' ' . count($commentRows) : '' ?></h1>

            <?php if ($commentError): ?><p class="form-error"><?= e($commentError) ?></p><?php endif; ?>

            <?php if ($user): ?>
                <form class="comment-form" method="post" action="comments.php">
                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                    <?php if ($fromProfile && $profileUsername !== ''): ?><input type="hidden" name="from" value="profile"><input type="hidden" name="u" value="<?= e($profileUsername) ?>"><?php endif; ?>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <textarea name="content" rows="2" placeholder="Write a comment..."></textarea>
                    <div class="comment-form-actions"><button class="button" type="submit">Comment</button></div>
                </form>
            <?php endif; ?>

            <?php if ($commentRows): ?>
                <?php renderCommentTree($comments, null, 0, $fromProfile ? 'profile' : '', $profileUsername); ?>
            <?php else: ?>
                <p class="empty">No comments yet.</p>
            <?php endif; ?>
        </section>
    </div>
</main>

<footer>
    <div class="wrap"><span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span><nav><a href="#">About</a><a href="#">Privacy</a><a href="#">Terms</a></nav></div>
</footer>

<script type="module">
import { initGalleries } from './assets/js/gallery.js';
initGalleries();
</script>

<script>
document.querySelectorAll('.post-menu').forEach(menu => {
    const button = menu.querySelector('.post-menu-button');
    const dropdown = menu.querySelector('.post-menu-dropdown');
    button.addEventListener('click', event => {
        event.stopPropagation();
        const open = !dropdown.hidden;
        document.querySelectorAll('.post-menu-dropdown').forEach(item => item.hidden = true);
        document.querySelectorAll('.post-menu-button').forEach(item => item.setAttribute('aria-expanded', 'false'));
        dropdown.hidden = open;
        button.setAttribute('aria-expanded', String(!open));
    });
});

document.querySelectorAll('.comment-reply-button').forEach(button => {
    button.addEventListener('click', () => {
        const form = document.querySelector('[data-reply-form="' + button.dataset.commentId + '"]');
        if (form) {
            form.hidden = !form.hidden;
            if (!form.hidden) form.querySelector('textarea').focus();
        }
    });
});

document.querySelectorAll('.comment-cancel-button').forEach(button => {
    button.addEventListener('click', () => {
        const form = document.querySelector('[data-reply-form="' + button.dataset.commentId + '"]');
        if (form) form.hidden = true;
    });
});

document.addEventListener('click', () => {
    document.querySelectorAll('.post-menu-dropdown').forEach(item => item.hidden = true);
    document.querySelectorAll('.post-menu-button').forEach(item => item.setAttribute('aria-expanded', 'false'));
});
</script>
</body>
</html>
