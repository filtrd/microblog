<?php
declare(strict_types=1);

const DB_PATH = __DIR__ . '/../data/microblog.sqlite';
const POST_URL_LENGTH = 23;
const POST_URL_PATTERN = '~https?://[^\s<]+~i';
const MAX_POST_IMAGES = 3;

require_once __DIR__ . '/video.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id, username, avatar_path FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) { header('Location: login.php'); exit; }
    return $user;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function set_flash(string $key, string $message): void { $_SESSION['flash'][$key] = $message; }
function get_flash(string $key): ?string { $message = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $message; }

function encodeFeedCursor(array $post): string
{
    $payload = json_encode([
        'created_at' => $post['created_at'],
        'id' => (int)$post['id'],
    ], JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
}

function decodeFeedCursor(string $cursor): array
{
    $encodedCursor = strtr($cursor, '-_', '+/');
    $encodedCursor .= str_repeat('=', (4 - strlen($encodedCursor) % 4) % 4);
    $decoded = base64_decode($encodedCursor, true);

    if ($decoded === false) throw new InvalidArgumentException('Invalid cursor');

    $data = json_decode($decoded, true);
    if (!is_array($data) || !isset($data['created_at'], $data['id']) || !is_string($data['created_at']) || !is_int($data['id'])) {
        throw new InvalidArgumentException('Invalid cursor');
    }

    return $data;
}

function postCharacterCount(string $content): int
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $count = 0; $offset = 0;
    if (preg_match_all(POST_URL_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $url = $match[0]; $position = $match[1];
            $count += mb_strlen(substr($content, $offset, $position - $offset));
            $trimmedUrl = rtrim($url, '.,!?;:)]}');
            $count += POST_URL_LENGTH + mb_strlen(substr($url, strlen($trimmedUrl)));
            $offset = $position + strlen($url);
        }
    }
    return $count + mb_strlen(substr($content, $offset));
}

function renderPostContent(string $content): string
{
    $output = ''; $offset = 0;
    if (preg_match_all(POST_URL_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $url = $match[0]; $position = $match[1];
            $output .= e(substr($content, $offset, $position - $offset));
            $trimmedUrl = rtrim($url, '.,!?;:)]}');
            $trailing = substr($url, strlen($trimmedUrl));
            $video = detectVideo($trimmedUrl);

            if ($video) {
                $output .= renderVideoEmbed($video) . e($trailing);
            } else {
                $parsed = parse_url($trimmedUrl);
                $host = preg_replace('~^www\\.~i', '', $parsed['host'] ?? $trimmedUrl);
                $path = trim($parsed['path'] ?? '', '/');
                $segments = $path === '' ? [] : explode('/', $path);
                $display = $host;
                if ($segments) { $display .= '/' . $segments[0]; if (count($segments) > 1) $display .= '…'; }
                elseif (!empty($parsed['query']) || !empty($parsed['fragment'])) $display .= '…';
                $output .= '<a class="post-link" href="' . e($trimmedUrl) . '" target="_blank" rel="noopener noreferrer">' . e($display) . '</a>' . e($trailing);
            }

            $offset = $position + strlen($url);
        }
    }
    return nl2br($output . e(substr($content, $offset)));
}

function getPostImages(array $post): array
{
    $stmt = db()->prepare('SELECT id, image_path FROM post_images WHERE post_id = ? ORDER BY position ASC, id ASC');
    $stmt->execute([(int)$post['id']]);
    $images = $stmt->fetchAll();

    if (!$images && !empty($post['image_path'])) {
        $images[] = ['id' => null, 'image_path' => $post['image_path']];
    }

    return $images;
}

function liked_by_me(int $postId): bool
{
    if (!isset($_SESSION['user_id'])) return false;
    $stmt = db()->prepare('SELECT 1 FROM likes WHERE user_id = ? AND post_id = ?');
    $stmt->execute([$_SESSION['user_id'], $postId]);
    return (bool)$stmt->fetchColumn();
}

function can_edit_post(array $post, array $user): bool
{
    global $postEditTime, $postEditCount;
    $age = time() - strtotime($post['created_at']);
    return (int)$post['user_id'] === (int)$user['id'] && $age >= 0 && $age <= (int)$postEditTime * 60 && (int)$post['edit_count'] < (int)$postEditCount;
}

function can_delete_post(array $post, array $user): bool
{
    global $postDeleteTime;
    $age = time() - strtotime($post['created_at']);
    return (int)$post['user_id'] === (int)$user['id'] && $age >= 0 && $age <= (int)$postDeleteTime * 60;
}

function renderCommentTree(array $comments, ?int $parentId = null, int $depth = 0, string $fromProfile = '', string $profileUsername = ''): void
{
    foreach ($comments[$parentId] ?? [] as $comment) {
        $id = (int)$comment['id'];
        $indent = min($depth, 4) * 20;
        ?>
        <div class="comment" id="comment-<?= $id ?>" style="margin-left: <?= $indent ?>px">
            <div class="comment-head">
                <a href="profile.php?u=<?= urlencode($comment['username']) ?>"><strong>@<?= e($comment['username']) ?></strong></a>
                <time><?= e(formatPostDate($comment['created_at'])) ?></time>
            </div>
            <div class="comment-content"><?= renderPostContent($comment['content']) ?></div>
            <?php if (current_user()): ?>
                <button type="button" class="comment-reply-button" data-comment-id="<?= $id ?>">Reply</button>
                <form class="comment-reply-form" method="post" action="comments.php" data-reply-form="<?= $id ?>" hidden>
                    <input type="hidden" name="post_id" value="<?= (int)$comment['post_id'] ?>">
                    <input type="hidden" name="parent_id" value="<?= $id ?>">
                    <?php if ($fromProfile === 'profile' && $profileUsername !== ''): ?><input type="hidden" name="from" value="profile"><input type="hidden" name="u" value="<?= e($profileUsername) ?>"><?php endif; ?>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <textarea name="content" rows="2" placeholder="Write a reply..."></textarea>
                    <div class="comment-form-actions"><button type="button" class="comment-cancel-button" data-comment-id="<?= $id ?>">Cancel</button><button class="button" type="submit">Reply</button></div>
                </form>
            <?php endif; ?>
        </div>
        <?php
        renderCommentTree($comments, $id, $depth + 1, $fromProfile, $profileUsername);
    }
}

function renderPost(array $post, ?array $user, string $redirect = 'index'): void
{
    $postId = (int)$post['id'];
    $commentUrl = 'comments.php?post_id=' . $postId;
    if ($redirect === 'profile') {
        $commentUrl .= '&from=profile&u=' . urlencode($post['username']);
    }
    $isLiked = $user ? liked_by_me($postId) : false;
    $images = getPostImages($post);
    ?>
    <article class="post" id="post-<?= $postId ?>">
        <div class="post-head">
            <a class="avatar-link" href="profile.php?u=<?= urlencode($post['username']) ?>">
                <?php if (!empty($post['avatar_path'])): ?><img class="avatar avatar-small" src="<?= e($post['avatar_path']) ?>" alt="">
                <?php else: ?><span class="avatar avatar-small avatar-fallback"><?= e(strtoupper(substr($post['username'], 0, 1))) ?></span><?php endif; ?>
            </a>
            <div class="post-author"><a href="profile.php?u=<?= urlencode($post['username']) ?>"><strong>@<?= e($post['username']) ?></strong></a><time><?= e(formatPostDate($post['created_at'])) ?></time></div>
            <?php if ($user && (int)$post['user_id'] === (int)$user['id'] && (can_edit_post($post, $user) || can_delete_post($post, $user))): ?>
                <div class="post-menu"><button type="button" class="post-menu-button" aria-label="Post menu" aria-expanded="false">…</button><div class="post-menu-dropdown" hidden>
                    <?php if (can_edit_post($post, $user)): ?><a href="edit.php?post_id=<?= $postId ?><?= $redirect === 'profile' ? '&redirect=profile' : '' ?>">Edit</a><?php endif; ?>
                    <?php if (can_delete_post($post, $user)): ?><form method="post" action="delete.php" class="post-delete-form"><input type="hidden" name="post_id" value="<?= $postId ?>"><?php if ($redirect === 'profile'): ?><input type="hidden" name="redirect" value="profile"><?php endif; ?><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit">Delete</button></form><?php endif; ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php if ($post['content'] !== ''): ?><div class="post-content"><?= renderPostContent($post['content']) ?></div><?php endif; ?>
        <?php if ($images): ?>
            <div class="post-gallery" data-gallery>
                <?php foreach ($images as $index => $image): ?>
                    <img class="post-gallery-image" src="<?= e($image['image_path']) ?>" alt="" <?= $index > 0 ? 'hidden' : '' ?>>
                <?php endforeach; ?>
                <?php if (count($images) > 1): ?>
                    <button type="button" class="post-gallery-arrow post-gallery-prev" data-gallery-prev aria-label="Previous image">‹</button>
                    <button type="button" class="post-gallery-arrow post-gallery-next" data-gallery-next aria-label="Next image">›</button>
                    <div class="post-gallery-dots" data-gallery-dots aria-label="Gallery navigation">
                        <?php foreach ($images as $index => $image): ?><button type="button" class="post-gallery-dot<?= $index === 0 ? ' is-active' : '' ?>" data-gallery-dot="<?= $index ?>" aria-label="Image <?= $index + 1 ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="post-actions">
            <?php if ($user): ?><form class="inline" method="post" action="like.php"><input type="hidden" name="post_id" value="<?= $postId ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button type="submit" class="like-button" aria-label="<?= $isLiked ? 'Unlike post' : 'Like post' ?>" aria-pressed="<?= $isLiked ? 'true' : 'false' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"></path></svg><span><?= (int)($post['like_count'] ?? 0) ?></span></button></form>
            <?php else: ?><span class="like-count"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"></path></svg><span><?= (int)($post['like_count'] ?? 0) ?></span></span><?php endif; ?>
            <a class="comment-toggle" href="<?= e($commentUrl) ?>" aria-label="View comments"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.5 9.17 9.17 0 0 1-4-.9L3 21l1.9-4.7A8.38 8.38 0 0 1 3 11.5 8.38 8.38 0 0 1 12 3a8.38 8.38 0 0 1 9 8.5z"></path></svg><span><?= (int)($post['comment_count'] ?? 0) ?></span></a>
            <?php if (!empty($post['updated_at'])): ?><span class="post-edited">Edited: <?= e(date('M j, Y H:i', strtotime($post['updated_at']))) ?></span><?php endif; ?>
        </div>
    </article>
    <?php
}

function formatPostDate(string $date): string
{
    $timestamp = strtotime($date); $diff = time() - $timestamp;
    if ($diff < 60) return '1 min ago';
    if ($diff < 3600) { $minutes = (int)floor($diff / 60); return $minutes . ' min' . ($minutes === 1 ? '' : 's') . ' ago'; }
    if ($diff < 86400) { $hours = (int)floor($diff / 3600); return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago'; }
    if ($diff < 604800) { $days = (int)floor($diff / 86400); return $days . ' day' . ($days === 1 ? '' : 's') . ' ago'; }
    if ($diff < 2592000) { $weeks = (int)floor($diff / 604800); return $weeks . ' wk' . ($weeks === 1 ? '' : 's') . ' ago'; }
    return date('M j, Y', $timestamp);
}

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
