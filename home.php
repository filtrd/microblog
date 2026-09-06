<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/config.php';

$user = current_user();
if (!$user) {
    header('Location: index.php');
    exit;
}

$postError = get_flash('post_error');
$postDraft = get_flash('post_draft') ?? '';
$editError = get_flash('edit_error');
$editDraft = get_flash('edit_draft');
$editId = (int)($_GET['edit'] ?? 0);
$editRedirect = ($_GET['redirect'] ?? '') === 'profile' ? 'profile' : 'index';
$editRedirectTarget = $editRedirect === 'profile'
    ? 'profile.php?u=' . urlencode($user['username'])
    : 'index.php';
$editPost = null;
$editImages = [];

if ($editId > 0) {
    $editStmt = db()->prepare('SELECT p.id, p.content, p.image_path, p.created_at, p.edit_count, u.id AS user_id, u.username FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND p.user_id = ?');
    $editStmt->execute([$editId, $user['id']]);
    $candidate = $editStmt->fetch();
    if ($candidate && can_edit_post($candidate, $user)) {
        $editPost = $candidate;
        $editImages = getPostImages($editPost);
    }
}

$feedPageSize = (int)$feedPageSize;
$feedQueryLimit = $feedPageSize + 1;

$stmt = db()->query(<<<SQL
SELECT p.id, p.content, p.image_path, p.created_at, p.updated_at, p.edit_count, u.id AS user_id, u.username, u.avatar_path,
       (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count,
       (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count
FROM posts p
JOIN users u ON u.id = p.user_id
ORDER BY p.created_at DESC, p.id DESC
LIMIT {$feedQueryLimit}
SQL);
$posts = $stmt->fetchAll();

$hasMorePosts = count($posts) > $feedPageSize;
if ($hasMorePosts) array_pop($posts);

$nextFeedCursor = null;
if ($hasMorePosts && $posts) $nextFeedCursor = encodeFeedCursor($posts[array_key_last($posts)]);

$editing = $editPost !== null;
$composerError = $editing ? $editError : $postError;
$composerDraft = $editing && $editDraft !== null ? $editDraft : ($editing ? $editPost['content'] : $postDraft);
$composerOpenOnLoad = $editing || (!$editing && $postError);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($siteName) ?> · <?= e($tagLine) ?></title>
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
            <?php if ($user): ?>
                <a class="profile-link" href="profile.php?u=<?= urlencode($user['username']) ?>">@<?= e($user['username']) ?></a>
                <form class="inline" method="post" action="logout.php">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <button>Log out</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main>
    <div class="wrap">
        <?php if (!$editing): ?>
            <button type="button" id="composer-open" aria-haspopup="dialog">What's happening?</button>
        <?php endif; ?>

        <?php if (!$editing && $postError): ?>
            <p class="form-error"><?= e($postError) ?></p>
        <?php endif; ?>

        <section class="feed" id="feed" data-next-cursor="<?= e($nextFeedCursor ?? '') ?>" data-has-more="<?= $hasMorePosts ? '1' : '0' ?>">
            <?php foreach ($posts as $post): ?>
                <?php renderPost($post, $user); ?>
            <?php endforeach; ?>

            <?php if (!$posts): ?>
                <p class="empty">No posts yet. Be the first!</p>
            <?php endif; ?>
        </section>
        <div id="feed-sentinel" aria-hidden="true"></div>
        <?php if ($hasMorePosts): ?><p id="feed-status" class="empty" hidden>Loading more posts…</p><?php endif; ?>
    </div>
</main>

<?php if ($editing): ?>
<dialog class="composer-dialog" aria-labelledby="composer-dialog-title" data-open-on-load="1" data-close-url="<?= e($editRedirectTarget) ?>">
    <div class="composer-dialog-head">
        <strong id="composer-dialog-title">Edit post</strong>
        <button type="button" id="composer-close">Close</button>
    </div>
    <p class="edit-info">You can edit this post <?= max(0, (int)$postEditCount - (int)$editPost['edit_count']) ?> more time<?= max(0, (int)$postEditCount - (int)$editPost['edit_count']) === 1 ? '' : 's' ?> within <?= max(0, (int)ceil(((int)$postEditTime * 60 - max(0, time() - strtotime($editPost['created_at']))) / 60)) ?> minute<?= max(0, (int)ceil(((int)$postEditTime * 60 - max(0, time() - strtotime($editPost['created_at']))) / 60)) === 1 ? '' : 's' ?>.</p>
    <form class="composer" method="post" action="edit.php" enctype="multipart/form-data" data-max-post-length="<?= (int)$maxPostLength ?>">
        <?php if ($composerError): ?>
            <p class="form-error"><?= e($composerError) ?></p>
        <?php endif; ?>
        <textarea name="content" placeholder="What's happening?"><?= e($composerDraft) ?></textarea>
        <input type="file" id="image-upload" name="images[]" accept="image/jpeg,image/png,image/webp" multiple hidden>
        <input type="hidden" id="image-urls" name="image_urls" value="[]">
        <input type="hidden" id="image-order" name="image_order" value="<?= e(json_encode(array_map(fn($image) => 'existing:' . (int)$image['id'], array_filter($editImages, fn($image) => $image['id'] !== null)), JSON_THROW_ON_ERROR)) ?>">

        <div id="selected-images" class="selected-images" aria-label="Selected images">
            <?php foreach ($editImages as $index => $image): ?>
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
                <input type="hidden" name="post_id" value="<?= (int)$editPost['id'] ?>">
                <input type="hidden" name="redirect" value="<?= e($editRedirect) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <a href="<?= e($editRedirectTarget) ?>">Cancel</a>
                <button class="button" type="submit">Save</button>
            </div>
        </div>
    </form>
</dialog>
<?php else: ?>
<dialog id="composer-dialog" aria-labelledby="composer-dialog-title" data-open-on-load="<?= $composerOpenOnLoad ? '1' : '0' ?>">
    <div class="composer-dialog-head">
        <strong id="composer-dialog-title">New post</strong>
        <button type="button" id="composer-close">Close</button>
    </div>
    <form class="composer" method="post" action="post.php" enctype="multipart/form-data" data-max-post-length="<?= (int)$maxPostLength ?>">
        <textarea name="content" placeholder="What's happening?"><?= e($composerDraft) ?></textarea>
        <input type="file" id="image-upload" name="images[]" accept="image/jpeg,image/png,image/webp" multiple hidden>
        <input type="hidden" id="image-urls" name="image_urls" value="[]">
        <input type="hidden" id="image-order" name="image_order" value="[]">

        <div id="selected-images" class="selected-images" aria-label="Selected images"></div>

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
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button class="button" type="submit">Post</button>
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

<dialog id="delete-dialog" aria-labelledby="delete-dialog-title">
    <p id="delete-dialog-title">Delete this post?</p>
    <form method="dialog">
        <button type="submit" value="cancel">Cancel</button>
        <button type="submit" value="confirm" autofocus>Delete</button>
    </form>
</dialog>

<footer>
    <div class="wrap topbar">
        <span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
        <nav><a href="#">About</a><a href="#">Privacy</a><a href="#">Terms</a></nav>
    </div>
</footer>

<script type="module" src="assets/js/app.js"></script>
</body>
</html>
