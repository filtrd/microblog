<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/functions.php';

if (current_user()) {
    header('Location: home.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($siteName) ?> · <?= e($tagLine) ?></title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/landing.css">
</head>
<body>
<header class="topbar">
    <div class="wrap">
        <div class="brand">
            <a class="logo" href="index.php"><?= e($siteName) ?></a>
            <p class="tagline"><?= e($tagLine) ?></p>
        </div>
        <nav>
            <a href="login.php">Log in</a>
            <a class="button" href="register.php">Sign up</a>
        </nav>
    </div>
</header>

<main>
    <div class="wrap">
        <section class="landing">
            <p class="landing-kicker">A quieter place for conversation.</p>
            <h1>Share what matters.<br>Follow people you care about.</h1>
            <p class="landing-copy">No noise required.</p>
            <a class="button landing-cta" href="register.php">Create an account</a>
        </section>
    </div>
</main>

<footer>
    <div class="wrap topbar">
        <span>&copy; <?= date('Y') ?> <?= e($siteName) ?></span>
        <nav><a href="#">About</a><a href="#">Privacy</a><a href="#">Terms</a></nav>
    </div>
</footer>
</body>
</html>