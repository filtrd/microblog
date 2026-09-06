<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/database.php';
require_once __DIR__ . '/inc/config.php';

if (current_user()) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
        $error = 'Username must be 3-30 characters: letters, numbers and underscores only.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            header('Location: index.php'); exit;
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'That username is already taken.' : 'Could not create account.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign up - <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/auth.css">
</head>
<body>
<main class="auth">
    <a class="logo" href="index.php"><?= e($siteName) ?></a>
    <section class="card">
        <h1>Create account</h1>
        <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
            <label>Username<input name="username" maxlength="30" required autofocus></label>
            <label>Password<input type="password" name="password" minlength="8" required></label>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <button class="button">Sign up</button>
        </form>
        <p>Already have an account? <a href="login.php">Log in</a></p>
    </section>
</main>
<footer>
    <div class="wrap">
        <span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
        <nav>
            <a href="#">About</a>
            <a href="#">Privacy</a>
            <a href="#">Terms</a>
        </nav>
    </div>
</footer>
</body>
</html>
