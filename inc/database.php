<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$pdo = db();

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    avatar_path TEXT,
    location TEXT,
    website TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    image_path TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    edit_count INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS post_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    image_path TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS likes (
    user_id INTEGER NOT NULL,
    post_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS follows (
    follower_id INTEGER NOT NULL,
    following_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_id, following_id),
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK (follower_id != following_id)
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    parent_id INTEGER,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
);
SQL);

foreach (['location', 'website'] as $column) {
    try {
        $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' TEXT');
    } catch (PDOException $e) {
        if (!str_contains(strtolower($e->getMessage()), 'duplicate column name')) throw $e;
    }
}

$pdo->exec(<<<'SQL'
INSERT INTO post_images (post_id, image_path, position)
SELECT p.id, p.image_path, 0
FROM posts p
WHERE p.image_path IS NOT NULL
  AND p.image_path <> ''
  AND NOT EXISTS (
      SELECT 1 FROM post_images pi WHERE pi.post_id = p.id
  )
SQL);

$pdo->exec('CREATE INDEX IF NOT EXISTS comments_post_id_idx ON comments(post_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS comments_parent_id_idx ON comments(parent_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS posts_created_id_idx ON posts(created_at DESC, id DESC)');
$pdo->exec('CREATE INDEX IF NOT EXISTS posts_user_created_id_idx ON posts(user_id, created_at DESC, id DESC)');
$pdo->exec('CREATE INDEX IF NOT EXISTS post_images_post_position_idx ON post_images(post_id, position, id)');
