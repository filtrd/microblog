<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update-profile-detail') {
    header('Content-Type: application/json; charset=utf-8');
    $user = require_login();
    verify_csrf();

    $field = $_POST['field'] ?? '';
    $value = trim((string)($_POST['value'] ?? ''));

    if ($field === 'location') {
        if (mb_strlen($value) > 100) {
            http_response_code(422);
            echo json_encode(['error' => 'Location is too long.']);
            exit;
        }
    } elseif ($field === 'website') {
        $value = preg_replace('~^https?://~i', '', $value);
        if ($value !== '' && (strlen($value) > 253 || !preg_match('~^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$~', $value))) {
            http_response_code(422);
            echo json_encode(['error' => 'Enter a valid website hostname, such as example.com.']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid profile field.']);
        exit;
    }

    $stmt = db()->prepare("UPDATE users SET {$field} = NULLIF(?, '') WHERE id = ?");
    $stmt->execute([$value, $user['id']]);
    echo json_encode(['field' => $field, 'value' => $value]);
    exit;
}

$username = trim($_GET['u'] ?? '');
$stmt = db()->prepare('SELECT id, username, avatar_path, location, website, created_at FROM users WHERE username = ?');
$stmt->execute([$username]);
$profile = $stmt->fetch();
if (!$profile) { http_response_code(404); exit('User not found'); }

$feedPageSize = (int)$feedPageSize;
$feedQueryLimit = $feedPageSize + 1;

$stmt = db()->prepare('SELECT p.id, p.content, p.image_path, p.created_at, p.updated_at, p.edit_count, u.id AS user_id, u.username, u.avatar_path, (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS like_count, (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comment_count FROM posts p JOIN users u ON u.id = p.user_id WHERE p.user_id = ? ORDER BY p.created_at DESC, p.id DESC LIMIT ' . $feedQueryLimit);
$stmt->execute([$profile['id']]);
$posts = $stmt->fetchAll();

$hasMorePosts = count($posts) > $feedPageSize;
if ($hasMorePosts) array_pop($posts);

$nextFeedCursor = null;
if ($hasMorePosts && $posts) $nextFeedCursor = encodeFeedCursor($posts[array_key_last($posts)]);

$stmt = db()->prepare('SELECT COUNT(*) FROM posts WHERE user_id = ?');
$stmt->execute([$profile['id']]);
$postCount = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM follows WHERE following_id = ?');
$stmt->execute([$profile['id']]);
$followerCount = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM follows WHERE follower_id = ?');
$stmt->execute([$profile['id']]);
$followingCount = (int)$stmt->fetchColumn();

$user = current_user();
$isFollowing = false;
if ($user && (int)$user['id'] !== (int)$profile['id']) {
    $stmt = db()->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?');
    $stmt->execute([$user['id'], $profile['id']]);
    $isFollowing = (bool)$stmt->fetchColumn();
}

$avatarError = trim($_GET['avatar_error'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>@<?= e($profile['username']) ?> · <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/profile.css">
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
        <section class="profile">
            <div class="profile-main">
                <?php if ($user && (int)$user['id'] === (int)$profile['id']): ?>
                    <form class="avatar-form" method="post" action="avatar.php" enctype="multipart/form-data">
                        <label class="avatar-upload" for="avatar-upload">
                            <?php if (!empty($profile['avatar_path'])): ?><img class="avatar avatar-profile" src="<?= e($profile['avatar_path']) ?>" alt="">
                            <?php else: ?><span class="avatar avatar-profile avatar-fallback"><?= e(strtoupper(substr($profile['username'], 0, 1))) ?></span><?php endif; ?>
                        </label>
                        <input type="file" id="avatar-upload" name="avatar" accept="image/jpeg,image/png,image/webp" hidden>
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    </form>
                <?php elseif (!empty($profile['avatar_path'])): ?><img class="avatar avatar-profile" src="<?= e($profile['avatar_path']) ?>" alt="">
                <?php else: ?><span class="avatar avatar-profile avatar-fallback"><?= e(strtoupper(substr($profile['username'], 0, 1))) ?></span><?php endif; ?>
                <div class="profile-main-info">
                    <div class="profile-name">
                        <h1>@<?= e($profile['username']) ?></h1>
                    </div>
                    <p>Joined <?= date('M Y', strtotime($profile['created_at'])) ?></p>
                    <p><?= $postCount ?> Posts &middot; <?= $followerCount ?> Followers &middot; <?= $followingCount ?> Following</p>
                    <div class="profile-details">
                        <div class="profile-detail" data-profile-detail="location" data-value="<?= e($profile['location'] ?? '') ?>">
                            <span aria-hidden="true">📍</span>
                            <span class="profile-detail-value"><?= $profile['location'] !== null && $profile['location'] !== '' ? e($profile['location']) : 'Add location' ?></span>
                            <?php if ($user && (int)$user['id'] === (int)$profile['id']): ?><button type="button" class="profile-detail-edit" aria-label="Edit location">✎</button><?php endif; ?>
                        </div>
                        <div class="profile-detail" data-profile-detail="website" data-value="<?= e($profile['website'] ?? '') ?>">
                            <span aria-hidden="true">🌐</span>
                            <?php if (!empty($profile['website'])): ?><a class="profile-detail-value" href="https://<?= e($profile['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($profile['website']) ?></a>
                            <?php else: ?><span class="profile-detail-value">Add website</span><?php endif; ?>
                            <?php if ($user && (int)$user['id'] === (int)$profile['id']): ?><button type="button" class="profile-detail-edit" aria-label="Edit website">✎</button><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($user && (int)$user['id'] !== (int)$profile['id']): ?>
                        <form class="follow-form" method="post" action="follow.php"><input type="hidden" name="user_id" value="<?= (int)$profile['id'] ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="button"><?= $isFollowing ? 'Following' : 'Follow' ?></button></form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($avatarError): ?><p class="error"><?= e($avatarError) ?></p><?php endif; ?>
        </section>

        <section class="feed" id="feed" data-profile-username="<?= e($profile['username']) ?>" data-next-cursor="<?= e($nextFeedCursor ?? '') ?>" data-has-more="<?= $hasMorePosts ? '1' : '0' ?>">
            <?php foreach ($posts as $post): ?>
                <?php renderPost($post, $user, 'profile'); ?>
            <?php endforeach; ?>
            <?php if (!$posts): ?><p class="empty">No posts yet.</p><?php endif; ?>
        </section>
        <div id="feed-sentinel" aria-hidden="true"></div>
        <?php if ($hasMorePosts): ?><p id="feed-status" class="empty" hidden>Loading more posts…</p><?php endif; ?>
    </div>
</main>

<dialog id="delete-dialog" aria-labelledby="delete-dialog-title">
    <p id="delete-dialog-title">Delete this post?</p>
    <form method="dialog">
        <button type="submit" value="cancel">Cancel</button>
        <button type="submit" value="confirm" autofocus>Delete</button>
    </form>
</dialog>

<footer>
    <div class="wrap"><span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span><nav><a href="#">About</a><a href="#">Privacy</a><a href="#">Terms</a></nav></div>
</footer>

<script type="module" src="assets/js/app.js"></script>
</body>
</html>
