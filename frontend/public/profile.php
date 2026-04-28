<?php
/**
 * Public member verification page — /members/{number}.
 *
 * No login required. Renders name, type, role, joined date, and either an
 * avatar (if the member's privacy toggle allows) or initials. Disabled
 * "+ Add as friend" button is a placeholder for the future friend system.
 */
declare(strict_types=1);

require_once DAEMS_SITE_PUBLIC . '/../src/MemberNumberFormatter.php';

$memberId = $__memberId ?? '';

$profile    = null;
$fetchError = null;

$ch = curl_init('http://daems-platform.local/api/v1/members/' . urlencode($memberId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Host: daems-platform.local',
    ],
]);
$raw  = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 404) {
    http_response_code(404);
    require DAEMS_SITE_PUBLIC . '/pages/errors/404.php';
    return;
}

if ($code >= 200 && $code < 300 && is_string($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
        $profile = $decoded['data'];
    }
}

if ($profile === null) {
    http_response_code(503);
    $errorCode    = 503;
    $errorTitle   = 'Service unavailable';
    $errorMessage = 'The member directory is temporarily unavailable. Please try again in a moment.';
    require DAEMS_SITE_PUBLIC . '/pages/errors/_template.php';
    return;
}

$displayedNumber = MemberNumberFormatter::format(
    isset($profile['member_number_raw']) ? (string) $profile['member_number_raw'] : '',
    isset($profile['tenant_member_number_prefix']) && is_string($profile['tenant_member_number_prefix'])
        ? $profile['tenant_member_number_prefix']
        : null,
);

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$memberName    = (string) ($profile['name'] ?? '');
$tenantName    = (string) ($profile['tenant_name'] ?? '');
$memberRole    = (string) ($profile['role'] ?? 'member');
$memberType    = (string) ($profile['member_type'] ?? 'basic');
$joinedAt      = (string) ($profile['joined_at'] ?? '');
$avatarVisible = !empty($profile['public_avatar_visible']);
$avatarUrl     = isset($profile['avatar_url']) ? (string) $profile['avatar_url'] : '';
$initials      = (string) ($profile['avatar_initials'] ?? '');
?><!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Member verification — Daem Society</title>

        <link rel="shortcut icon" href="/assets/img/brand/daems-favicon.svg" />

        <link rel="stylesheet" href="/assets/css/bootstrap.min.css" />
        <link rel="stylesheet" href="/assets/css/bootstrap-icons.min.css" />
        <link rel="stylesheet" href="/assets/css/daems.css" />
        <link rel="stylesheet" href="/assets/css/daems-search.css" />
        <link rel="stylesheet" href="/assets/css/public-member-page.css" />
    </head>
    <body>

        <?php include DAEMS_SITE_PUBLIC . '/partials/top-nav.php'; ?>

        <main>
            <div class="public-member-page">
                <div class="public-member-card">

                    <div class="public-member-badge">
                        <span aria-hidden="true">&check;</span>
                        Verified <?= $esc($tenantName) ?> member
                    </div>

                    <?php if ($avatarVisible && $avatarUrl !== ''): ?>
                        <img class="public-member-avatar" src="<?= $esc($avatarUrl) ?>" alt="" />
                    <?php else: ?>
                        <div class="public-member-avatar public-member-avatar--initials"><?= $esc($initials) ?></div>
                    <?php endif; ?>

                    <h1 class="public-member-name"><?= $esc($memberName) ?></h1>
                    <p class="public-member-sub"><?= $esc(ucfirst($memberRole)) ?> &middot; <?= $esc($tenantName) ?></p>

                    <dl class="public-member-fields">
                        <div><dt>Member &#8470;</dt><dd><?= $esc($displayedNumber !== '' ? $displayedNumber : '—') ?></dd></div>
                        <div><dt>Type</dt><dd><?= $esc(ucfirst($memberType)) ?></dd></div>
                        <div><dt>Role</dt><dd><?= $esc(ucfirst($memberRole)) ?></dd></div>
                        <div><dt>Since</dt><dd><?= $esc($joinedAt !== '' ? $joinedAt : '—') ?></dd></div>
                    </dl>

                    <div class="public-member-actions">
                        <button type="button" class="btn btn-disabled" disabled>+ Add as friend</button>
                        <small class="public-member-soon">Coming soon</small>
                    </div>

                    <p class="public-member-disclaimer">This is a public verification page. Daem Society does not share contact details or private information here.</p>
                </div>
            </div>
        </main>

        <?php include DAEMS_SITE_PUBLIC . '/partials/footer.php'; ?>

        <script src="/assets/js/bootstrap.bundle.min.js"></script>
        <script src="/assets/js/daems-search.js"></script>
    </body>
</html>
