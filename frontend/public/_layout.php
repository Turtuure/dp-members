<?php
/**
 * Shared wrapper for /members/* pages.
 *
 * Matches the visual pattern of the public legal docs (.docs-page)
 * — centered column, consistent h1/h2 scale — with one addition:
 * a "Vain jäsenille" pill under the title. Gates on tenant role;
 * anonymous visitors are bounced to /join#signin, non-members to /about.
 *
 * Including templates must set:
 *   $memberPageTitle  (string) — document title + H1
 *   $memberPageIntro  (string, optional) — muted lead paragraph
 *   $memberPageBody   (string, HTML)     — main content
 */

if (!class_exists('ApiClient')) {
    require_once DAEMS_SITE_PUBLIC . '/../src/ApiClient.php';
}
if (!class_exists('I18n')) {
    require_once DAEMS_SITE_PUBLIC . '/../src/I18n.php';
}

$__u = $_SESSION['user'] ?? null;
$__role = $__u['role'] ?? '';
$__isMember = !empty($__u) && in_array($__role, ['member', 'moderator', 'admin', 'global_system_administrator'], true);

if (!$__isMember) {
    if (empty($__u)) {
        $_SESSION['post_login_redirect'] = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /join#signin');
    } else {
        header('Location: /about');
    }
    exit;
}

$__title = $memberPageTitle ?? 'Jäsenalue';
$__intro = $memberPageIntro ?? '';
$__body  = $memberPageBody  ?? '';
$__esc   = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $__esc($__title) ?> — Daem Society</title>
    <link rel="shortcut icon" href="/assets/img/brand/daems-favicon.svg">
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/assets/css/daems.css">
    <link rel="stylesheet" href="/assets/css/daems-search.css">
</head>
<body>
    <?php include DAEMS_SITE_PUBLIC . '/partials/top-nav.php'; ?>

    <main class="docs-page docs-page--members">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">

                    <nav class="docs-breadcrumb" aria-label="Breadcrumb">
                        <a href="/about"><?= I18n::e('members.breadcrumb.about') ?></a>
                        <span class="mx-1">›</span>
                        <span><?= $__esc($__title) ?></span>
                    </nav>

                    <h1><?= $__esc($__title) ?></h1>
                    <div class="docs-page__meta">
                        <span class="docs-gate"><i class="bi bi-person-badge"></i> <?= I18n::e('members.gate_pill') ?></span>
                        <?php if ($__intro !== ''): ?>
                            <span class="docs-page__intro"><?= $__esc($__intro) ?></span>
                        <?php endif; ?>
                    </div>

                    <hr>

                    <?= $__body ?>

                </div>
            </div>
        </div>
    </main>

    <?php include DAEMS_SITE_PUBLIC . '/partials/footer.php'; ?>
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/daems.js"></script>
    <script src="/assets/js/daems-search.js"></script>
</body>
</html>
