<?php
/**
 * Backstage Members — single-page register + applications hub.
 *
 * One canonical URL (/backstage/members) shows two stacked KPI strips
 * (Register on top, Pending below) plus the register table. KPI cards are
 * clickable shortcuts:
 *   - Register KPIs → register-table filter shortcuts (status/type/sort)
 *   - Pending KPIs  → ?view=pending|approved|rejected subviews
 *
 * Sub-views (controlled by ?view):
 *   - null      → default register table
 *   - pending   → member + supporter applications list (Approve/Reject)
 *   - approved  → 30-day decided list (read-only)
 *   - rejected  → 30-day decided list (read-only)
 */

declare(strict_types=1);

use Daems\Frontend\ApiClient;

$pageTitle = 'backstage.title.members';
$activePage = 'members';
$breadcrumbs = [];

$viewRaw = $_GET['view'] ?? null;
$view = in_array($viewRaw, ['pending', 'approved', 'rejected'], true) ? (string) $viewRaw : null;

// ---------------------------------------------------------------------------
// State for both views
// ---------------------------------------------------------------------------
$flashSuccess = null;
$flashError = null;

// Pending-view approve/reject side-channel state
$inviteUrl = null;
$inviteExpiresAt = null;
$assignedNumber = null;
$assignedNumberLabel = null;

$isGsa = (string) ($_SESSION['user']['role'] ?? '') === 'global_system_administrator';

// ---------------------------------------------------------------------------
// POST dispatch — routed by view
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($view === 'pending') {
        // Approve / Reject application (member or supporter)
        $type = trim((string) ($_POST['type'] ?? ''));
        $id = trim((string) ($_POST['id'] ?? ''));
        $decision = trim((string) ($_POST['decision'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if (!in_array($type, ['member', 'supporter'], true) || $id === '' || !in_array($decision, ['approved', 'rejected'], true)) {
            $flashError = 'Invalid application decision payload.';
        } else {
            $result = ApiClient::post('/backstage/applications/' . rawurlencode($type) . '/' . rawurlencode($id) . '/decision', [
                'decision' => $decision,
                'note' => $note,
            ]);

            if (($result['status'] ?? 500) === 200) {
                if ($decision === 'approved') {
                    $flashSuccess = 'Application approved successfully.';
                    $payload = $result['body']['data'] ?? $result['body'] ?? [];
                    if (is_array($payload) && !empty($payload['invite_url'])) {
                        $inviteUrl = (string) $payload['invite_url'];
                        $inviteExpiresAt = is_string($payload['invite_expires_at'] ?? null) ? $payload['invite_expires_at'] : null;
                        if (!empty($payload['member_number'])) {
                            $assignedNumber = (string) $payload['member_number'];
                            $assignedNumberLabel = 'Member number';
                        } elseif (!empty($payload['supporter_number'])) {
                            $assignedNumber = (string) $payload['supporter_number'];
                            $assignedNumberLabel = 'Supporter number';
                        }
                    }
                } else {
                    $flashSuccess = 'Application rejected successfully.';
                }
            } else {
                $flashError = (string) ($result['body']['error'] ?? 'Failed to process application.');
            }
        }
    } else {
        // Register-tab status change (activate/suspend/terminate)
        $memberId = trim((string) ($_POST['member_id'] ?? ''));
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (!$isGsa) {
            $flashError = 'Only GSA can change member status.';
        } elseif ($memberId === '' || $newStatus === '') {
            $flashError = 'Invalid member status request.';
        } elseif ($reason === '') {
            $flashError = 'Status change reason is required.';
        } else {
            $result = ApiClient::post('/backstage/members/' . rawurlencode($memberId) . '/status', [
                'status' => $newStatus,
                'reason' => $reason,
            ]);

            if (($result['status'] ?? 500) === 200) {
                $flashSuccess = 'Member status updated successfully.';
            } else {
                $flashError = (string) ($result['body']['error'] ?? 'Failed to update member status.');
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Register-tab data fetch
// ---------------------------------------------------------------------------
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$typeFilter = trim((string) ($_GET['type'] ?? ''));
$qFilter = trim((string) ($_GET['q'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'member_number'));
$dir = strtoupper(trim((string) ($_GET['dir'] ?? 'ASC')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));

$filters = [
    'status' => $statusFilter,
    'type' => $typeFilter,
    'q' => $qFilter,
    'sort' => $sort,
    'dir' => $dir,
    'page' => $page,
    'per_page' => $perPage,
];

$data = ApiClient::get('/backstage/members', $filters);
if (!is_array($data)) {
    $data = ['items' => [], 'total' => 0];
}

$members = is_array($data['items'] ?? null) ? $data['items'] : [];
$total = (int) ($data['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));

// CSV export branch — only on the default register view
if ($view === null && ($_GET['export'] ?? '') === 'csv') {
    $exportRows = ApiClient::get('/backstage/members', [
        'status' => $statusFilter,
        'type' => $typeFilter,
        'q' => $qFilter,
        'sort' => $sort,
        'dir' => $dir,
        'page' => 1,
        'per_page' => 2000,
    ]);
    $rows = is_array($exportRows['items'] ?? null) ? $exportRows['items'] : [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members-register.csv"');

    $out = fopen('php://output', 'wb');
    if ($out !== false) {
        fputcsv($out, [
            'Member Number',
            'Name',
            'Email',
            'Tier',
            'Status',
            'Joined Date',
            'Country',
            'Date of Birth',
            'Status Reason',
        ]);

        foreach ($rows as $row) {
            $tier = $memberTier((string) ($row['name'] ?? ''), (string) ($row['membership_type'] ?? ''));

            fputcsv($out, [
                (string) ($row['member_number'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['email'] ?? ''),
                $tier['label'],
                (string) ($row['membership_status'] ?? ''),
                (string) (($row['membership_started_at'] ?? '') ?: ($row['created_at'] ?? '')),
                (string) ($row['country'] ?? ''),
                (string) ($row['date_of_birth'] ?? ''),
                (string) ($row['membership_status_reason'] ?? ''),
            ]);
        }

        fclose($out);
    }

    exit;
}

$auditFor = trim((string) ($_GET['audit'] ?? ''));
$auditRows = [];
if ($auditFor !== '') {
    $auditData = ApiClient::get('/backstage/members/' . rawurlencode($auditFor) . '/audit', ['limit' => 25]);
    if (is_array($auditData)) {
        $auditRows = $auditData;
    }
}

$keepQuery = [
    'status' => $statusFilter,
    'type' => $typeFilter,
    'q' => $qFilter,
    'sort' => $sort,
    'dir' => $dir,
    'per_page' => $perPage,
];

$buildQuery = static function (array $extra) use ($keepQuery): string {
    $merged = array_merge($keepQuery, $extra);
    $merged = array_filter($merged, static fn($v) => $v !== '' && $v !== null);
    $qs = http_build_query($merged);
    return $qs === '' ? '' : ('?' . $qs);
};

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

/**
 * Hardcoded founding-member roster — these names always render as "Perustajajäsen"
 * regardless of their stored membership_type. Backstage-only override; the
 * user-facing member card (profile/overview.php) keeps its own labelling.
 *
 * Match is case- and whitespace-insensitive.
 */
$foundingMembers = [
    'samppa turunen',
    'sampsa laine',
    'markus avonius',
];
$isFoundingMember = static function (string $name) use ($foundingMembers): bool {
    $needle = mb_strtolower(trim($name));
    return in_array($needle, $foundingMembers, true);
};
$memberTier = static function (string $name, string $type) use ($isFoundingMember): array {
    if ($isFoundingMember($name)) {
        return ['label' => 'Perustajajäsen', 'pill' => 'pill--founder'];
    }
    return match ($type) {
        'supporter'        => ['label' => 'Kannattava jäsen', 'pill' => 'pill--featured'],
        'honorary'         => ['label' => 'Kunniajäsen',      'pill' => 'pill--published'],
        'full', 'founding' => ['label' => 'Varsinainen jäsen', 'pill' => 'pill--scheduled'],
        default            => ['label' => 'Perusjäsen',       'pill' => 'pill--draft'],
    };
};

$nextStatuses = static function (string $current): array {
    return match ($current) {
        'active' => ['suspended' => 'Suspended', 'terminated' => 'Terminated'],
        'suspended' => ['active' => 'Active', 'terminated' => 'Terminated'],
        default => [],
    };
};
$sortLink = static function (string $key) use ($sort, $dir, $buildQuery): string {
    $nextDir = ($sort === $key && $dir === 'ASC') ? 'DESC' : 'ASC';
    return '/backstage/members' . $buildQuery(['sort' => $key, 'dir' => $nextDir, 'page' => 1]);
};
$sortMark = static function (string $key) use ($sort, $dir): string {
    if ($sort !== $key) {
        return '';
    }
    return $dir === 'DESC' ? ' ↓' : ' ↑';
};

// ---------------------------------------------------------------------------
// Pending applications fetch — needed by the pending sub-view AND to populate
// the count badge on the Pending KPI card. Cheap call so we always do it.
// ---------------------------------------------------------------------------
$pending = ApiClient::get('/backstage/applications/pending', ['limit' => 200]);
if (!is_array($pending)) {
    $pending = ['member' => [], 'supporter' => [], 'total' => 0];
}

$memberPending = is_array($pending['member'] ?? null) ? $pending['member'] : [];
$supporterPending = is_array($pending['supporter'] ?? null) ? $pending['supporter'] : [];
$totalPending = (int) ($pending['total'] ?? (count($memberPending) + count($supporterPending)));

// Decided list fetch (only for ?view=approved|rejected)
$decidedMember = [];
$decidedSupporter = [];
if ($view === 'approved' || $view === 'rejected') {
    $decided = ApiClient::get('/backstage/applications/decided', [
        'decision' => $view,
        'days'     => 30,
        'limit'    => 200,
    ]);
    if (is_array($decided)) {
        $decidedMember = is_array($decided['member'] ?? null) ? $decided['member'] : [];
        $decidedSupporter = is_array($decided['supporter'] ?? null) ? $decided['supporter'] : [];
    }
}

// ---------------------------------------------------------------------------
// Application detail fetch (?detail=ID&kind=member|supporter)
// Detail mode takes priority over view rendering.
// ---------------------------------------------------------------------------
$detailIdRaw   = trim((string) ($_GET['detail'] ?? ''));
$detailKindRaw = trim((string) ($_GET['kind']   ?? ''));
$detailMode = ($detailIdRaw !== '' && in_array($detailKindRaw, ['member', 'supporter'], true));

$detailApp = null;
if ($detailMode) {
    $detailResp = ApiClient::get('/backstage/applications/' . rawurlencode($detailKindRaw) . '/' . rawurlencode($detailIdRaw));
    if (is_array($detailResp) && isset($detailResp['application']) && is_array($detailResp['application'])) {
        $detailApp = $detailResp['application'];
    }
}

ob_start();
?>
<div class="members-register">
<?php if ($detailMode && $detailApp !== null):
    // ============================================================ Application detail (read-only)
    $appStatus = (string) ($detailApp['status'] ?? 'pending');
    $appDecidedAt = (string) ($detailApp['decided_at'] ?? '');
    $appDecisionNote = (string) ($detailApp['decision_note'] ?? '');
    $appCreatedAt = (string) ($detailApp['created_at'] ?? '');

    $isMember = $detailKindRaw === 'member';
    $detailTitle = $isMember
        ? 'Member application — ' . (string) ($detailApp['name'] ?? '')
        : 'Supporter application — ' . (string) ($detailApp['org_name'] ?? '');

    $statusLabel = match ($appStatus) {
        'approved' => 'Approved' . ($appDecidedAt !== '' ? ' on ' . substr($appDecidedAt, 0, 10) : ''),
        'rejected' => 'Rejected' . ($appDecidedAt !== '' ? ' on ' . substr($appDecidedAt, 0, 10) : ''),
        default    => 'Pending decision',
    };

?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $esc($detailTitle) ?></h1>
        <p class="page-header__subtitle"><?= $esc($statusLabel) ?></p>
    </div>
    <div>
        <a href="/backstage/members" class="btn btn--outline">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
            Back to members
        </a>
    </div>
</div>

<div class="card members-detail-card">
    <div class="card__body members-detail">
        <div class="members-detail__col members-detail__col--info">
            <?php if ($isMember): ?>
                <dl class="members-detail__list">
                    <div><dt>Name</dt><dd><?= $esc((string) ($detailApp['name'] ?? '-')) ?></dd></div>
                    <div><dt>Email</dt><dd><?= $esc((string) ($detailApp['email'] ?? '-')) ?></dd></div>
                    <div><dt>Date of birth</dt><dd><?= $esc((string) ($detailApp['date_of_birth'] ?? '-')) ?></dd></div>
                    <div><dt>Country</dt><dd><?= $esc((string) ($detailApp['country'] ?? '-')) ?></dd></div>
                    <div><dt>How heard</dt><dd><?= $esc((string) ($detailApp['how_heard'] ?? '-')) ?></dd></div>
                    <div><dt>Submitted</dt><dd><?= $esc($appCreatedAt !== '' ? substr($appCreatedAt, 0, 16) : '-') ?></dd></div>
                </dl>
            <?php else: ?>
                <dl class="members-detail__list">
                    <div><dt>Organization</dt><dd><?= $esc((string) ($detailApp['org_name'] ?? '-')) ?></dd></div>
                    <div><dt>Contact person</dt><dd><?= $esc((string) ($detailApp['contact_person'] ?? '-')) ?></dd></div>
                    <div><dt>Reg. no</dt><dd><?= $esc((string) ($detailApp['reg_no'] ?? '-')) ?></dd></div>
                    <div><dt>Email</dt><dd><?= $esc((string) ($detailApp['email'] ?? '-')) ?></dd></div>
                    <div><dt>Country</dt><dd><?= $esc((string) ($detailApp['country'] ?? '-')) ?></dd></div>
                    <div><dt>How heard</dt><dd><?= $esc((string) ($detailApp['how_heard'] ?? '-')) ?></dd></div>
                    <div><dt>Submitted</dt><dd><?= $esc($appCreatedAt !== '' ? substr($appCreatedAt, 0, 16) : '-') ?></dd></div>
                </dl>
            <?php endif; ?>
        </div>

        <div class="members-detail__col members-detail__col--motivation">
            <h3 class="members-detail__heading">Motivation</h3>
            <p class="members-detail__motivation"><?= nl2br($esc((string) ($detailApp['motivation'] ?? ''))) ?></p>

            <?php if ($appStatus === 'pending' && $isGsa): ?>
                <form method="post" action="/backstage/members?view=pending" class="members-detail__decision-form">
                    <input type="hidden" name="type" value="<?= $esc($detailKindRaw) ?>">
                    <input type="hidden" name="id" value="<?= $esc((string) ($detailApp['id'] ?? '')) ?>">
                    <label class="members-detail__note-label">
                        <span>Decision note (optional)</span>
                        <textarea name="note" rows="3" placeholder="Optional review note for this applicant"></textarea>
                    </label>
                    <div class="members-detail__decision-actions">
                        <button type="submit" name="decision" value="rejected" class="btn btn--danger-outline">Reject</button>
                        <button type="submit" name="decision" value="approved" class="btn btn--success-outline">Approve</button>
                    </div>
                </form>
                <!-- Governance integration (Option B — side-by-side button).
                     Clicking this button triggers the governance flow via JS:
                     fetches delegation state, then calls POST /api/backstage/governance/decisions/approve-basic
                     (or opens a vote_visibility prompt when not delegated to admin).
                     GSA users also get a force-approve override. -->
                <div class="members-detail__governance-actions"
                     data-governance-app-id="<?= $esc((string) ($detailApp['id'] ?? '')) ?>"
                     data-governance-app-kind="<?= $esc($detailKindRaw) ?>">
                    <button type="button"
                            class="btn btn--outline js-gov-propose-approve"
                            title="Luo hallituspäätös perusjäsenhyväksynnälle">
                        Ehdota hallitukselle
                    </button>
                    <?php if ($isGsa): ?>
                    <button type="button"
                            class="btn btn--danger-outline js-gov-force-approve"
                            title="GSA-ylikuittaus: ohita delegointi ja hyväksy välittömästi">
                        Pakkohyväksy (GSA)
                    </button>
                    <?php endif; ?>
                </div>
                <script src="/backstage/pages/governance/application-gov.js" defer></script>
            <?php elseif ($appStatus === 'approved' || $appStatus === 'rejected'): ?>
                <div class="members-detail__decision-meta">
                    <h3 class="members-detail__heading">Decision</h3>
                    <dl class="members-detail__list">
                        <div><dt>Outcome</dt><dd><?= $esc(ucfirst($appStatus)) ?></dd></div>
                        <?php if ($appDecidedAt !== ''): ?>
                            <div><dt>Decided at</dt><dd><?= $esc(substr($appDecidedAt, 0, 16)) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($appDecisionNote !== ''): ?>
                            <div><dt>Note</dt><dd><?= nl2br($esc($appDecisionNote)) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: // ============================================================ List + register views (default) ?>
<?php
// Subtitle changes per view to keep the header informative.
$pageSubtitle = match ($view) {
    'pending'  => 'Pending member and supporter applications awaiting decision.',
    'approved' => 'Approved applications from the last 30 days.',
    'rejected' => 'Rejected applications from the last 30 days.',
    default    => 'Official register for kannattava, perus, varsinainen, and kunniajäsen categories.',
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Members</h1>
        <p class="page-header__subtitle"><?= $esc($pageSubtitle) ?></p>
    </div>
    <div class="members-header-actions">
        <?php
        // Front page = bare /backstage/members with no query string.
        $isFrontPage = ($_SERVER['QUERY_STRING'] ?? '') === '';
        ?>
        <?php if (!$isFrontPage): ?>
            <a href="/backstage/members" class="btn btn--outline">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                Back to members
            </a>
        <?php endif; ?>
        <?php if ($view === null): ?>
            <a href="/backstage/members<?= $buildQuery(['export' => 'csv', 'page' => 1]) ?>" class="btn btn--success-outline">Export CSV</a>
        <?php endif; ?>
    </div>
</div>

<?php
// ===== KPI strip 1: Register =====
$icon_member  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
$icon_plus    = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 5v14M5 12h14"/></svg>';
$icon_heart   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
$icon_pause   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';

$registerKpis = [
    ['kpi_id' => 'total_members', 'label' => 'Total members', 'value' => '—', 'icon_html' => $icon_member, 'icon_variant' => 'blue',   'trend_label' => 'active in tenant', 'trend_direction' => 'muted', 'href' => '/backstage/members'],
    ['kpi_id' => 'new_members',   'label' => 'New (30d)',     'value' => '—', 'icon_html' => $icon_plus,   'icon_variant' => 'green',  'trend_label' => 'last 30 days',     'trend_direction' => 'muted', 'href' => '/backstage/members?sort=joined_at&dir=DESC'],
    ['kpi_id' => 'supporters',    'label' => 'Supporters',    'value' => '—', 'icon_html' => $icon_heart,  'icon_variant' => 'purple', 'trend_label' => 'supporting members', 'trend_direction' => 'muted', 'href' => '/backstage/members?type=supporter'],
    ['kpi_id' => 'inactive',      'label' => 'Inactive',      'value' => '—', 'icon_html' => $icon_pause,  'icon_variant' => 'amber',  'trend_label' => 'paused status',    'trend_direction' => 'warn',  'href' => '/backstage/members?status=suspended'],
];

// ===== KPI strip 2: Pending applications =====
$icon_clock     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
$icon_check     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 6L9 17l-5-5"/></svg>';
$icon_x         = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 6L6 18M6 6l12 12"/></svg>';
$icon_hourglass = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 22h14M5 2h14M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22M17 2v4.172a2 2 0 0 1-.586 1.414L12 12 7.586 7.586A2 2 0 0 1 7 6.172V2"/></svg>';

$pendingKpis = [
    ['kpi_id' => 'pending',            'label' => 'Pending',         'value' => '—', 'icon_html' => $icon_clock,     'icon_variant' => 'amber',  'trend_label' => 'awaiting decision', 'trend_direction' => 'warn',  'href' => '/backstage/members?view=pending'],
    ['kpi_id' => 'approved_30d',       'label' => 'Approved (30d)',  'value' => '—', 'icon_html' => $icon_check,     'icon_variant' => 'green',  'trend_label' => 'last 30 days',      'trend_direction' => 'muted', 'href' => '/backstage/members?view=approved'],
    ['kpi_id' => 'rejected_30d',       'label' => 'Rejected (30d)',  'value' => '—', 'icon_html' => $icon_x,         'icon_variant' => 'red',    'trend_label' => 'last 30 days',      'trend_direction' => 'muted', 'href' => '/backstage/members?view=rejected'],
    ['kpi_id' => 'avg_response_hours', 'label' => 'Avg response',    'value' => '—', 'icon_html' => $icon_hourglass, 'icon_variant' => 'gray',   'trend_label' => 'created → decided', 'trend_direction' => 'muted'],
];
?>
<div class="kpis-grid">
  <?php foreach ($registerKpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
</div>
<div class="kpis-grid">
  <?php foreach ($pendingKpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
</div>
<script src="/modules/members/assets/backstage/members-stats.js" defer></script>
<script src="/modules/members/assets/backstage/applications-stats.js" defer></script>

<?php if ($flashSuccess !== null): ?>
<div class="card members-flash members-flash--success">
    <div class="card__body"><?= $esc($flashSuccess) ?></div>
</div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="card members-flash members-flash--error">
    <div class="card__body"><?= $esc($flashError) ?></div>
</div>
<?php endif; ?>

<?php if ($view === null): ?>
<!-- ============================================================ Default register view -->
<div class="card members-table-card">
    <div class="card__body members-table-body">

        <!-- Inline filter row -->
        <form method="get" action="/backstage/members" class="members-filters-row"
              id="members-filter-form" aria-label="Filter members">
            <input type="hidden" name="sort" value="<?= $esc($sort) ?>">
            <input type="hidden" name="dir" value="<?= $esc($dir) ?>">
            <input type="text" name="q" value="<?= $esc($qFilter) ?>" placeholder="Search name, email, member #" class="members-filters-row__search">
            <select name="status">
                <option value="">All statuses</option>
                <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                <option value="suspended"<?= $statusFilter === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                <option value="terminated"<?= $statusFilter === 'terminated' ? ' selected' : '' ?>>Terminated</option>
            </select>
            <select name="type">
                <option value="">All categories</option>
                <option value="basic"<?= $typeFilter === 'basic' ? ' selected' : '' ?>>Perusjäsen</option>
                <option value="full"<?= $typeFilter === 'full' ? ' selected' : '' ?>>Varsinainen jäsen</option>
                <option value="supporter"<?= $typeFilter === 'supporter' ? ' selected' : '' ?>>Kannattava jäsen</option>
                <option value="honorary"<?= $typeFilter === 'honorary' ? ' selected' : '' ?>>Kunniajäsen</option>
            </select>
            <select name="per_page" aria-label="Rows per page">
                <option value="25"<?= $perPage === 25 ? ' selected' : '' ?>>25 / page</option>
                <option value="50"<?= $perPage === 50 ? ' selected' : '' ?>>50 / page</option>
                <option value="100"<?= $perPage === 100 ? ' selected' : '' ?>>100 / page</option>
                <option value="200"<?= $perPage === 200 ? ' selected' : '' ?>>200 / page</option>
            </select>
            <button type="submit" class="btn btn--outline btn--sm">Apply</button>
            <span class="members-filters-row__meta"><?= $total ?> records · page <?= $page ?> / <?= $totalPages ?></span>
        </form>
        <table class="data-table members-table">
            <thead>
                <tr>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('member_number')) ?>">Member #<?= $esc($sortMark('member_number')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('name')) ?>">Name<?= $esc($sortMark('name')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('email')) ?>">Email<?= $esc($sortMark('email')) ?></a></th>
                    <th>Tier</th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('membership_status')) ?>">Status<?= $esc($sortMark('membership_status')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('joined_at')) ?>">Joined<?= $esc($sortMark('joined_at')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('country')) ?>">Country<?= $esc($sortMark('country')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('dob')) ?>">Date of Birth<?= $esc($sortMark('dob')) ?></a></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$members): ?>
                <tr><td colspan="9" class="members-empty">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <?php
                        $memberId = (string) ($member['id'] ?? '');
                        $memberType = (string) ($member['membership_type'] ?? '');
                        $memberName = (string) ($member['name'] ?? '');
                        $tier = $memberTier($memberName, $memberType);
                        $subTierSlug = (string) ($member['subtier_slug'] ?? '');
                        $currentStatus = (string) ($member['membership_status'] ?? '');
                        $statusPill = match ($currentStatus) {
                            'active'     => 'pill--published', // green
                            'suspended'  => 'pill--archived',  // amber
                            'terminated' => 'pill--draft',     // neutral
                            default      => 'pill--draft',
                        };
                        $allowedNext = $nextStatuses($currentStatus);
                        $canActivate = isset($allowedNext['active']);
                        $canSuspend = isset($allowedNext['suspended']);
                        $canTerminate = isset($allowedNext['terminated']);
                        $joinedAt = (string) (($member['membership_started_at'] ?? '') ?: ($member['created_at'] ?? ''));
                    ?>
                    <tr>
                        <td><?= $esc((string) ($member['member_number'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($member['name'] ?? '')) ?></td>
                        <td><?= $esc((string) ($member['email'] ?? '')) ?></td>
                        <td>
                            <span class="pill <?= $esc($tier['pill']) ?>"><?= $esc($tier['label']) ?></span>
                            <?php if ($subTierSlug !== ''): ?>
                                <span class="badge badge--honor"><?= $esc($subTierSlug) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill <?= $esc($statusPill) ?>"><?= $esc(ucfirst($currentStatus)) ?></span>
                        </td>
                        <td><?= $esc($joinedAt !== '' ? substr($joinedAt, 0, 10) : '-') ?></td>
                        <td><?= $esc((string) ($member['country'] ?? '-')) ?></td>
                        <td><?= $esc((string) (($member['date_of_birth'] ?? '') ?: '-')) ?></td>
                        <td class="members-actions-cell">
                            <?php if (!$isGsa): ?>
                                <span class="members-actions-disabled">GSA only</span>
                            <?php else: ?>
                            <div class="members-action-menu">
                                <button type="button" class="members-action-menu__trigger" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?= $esc((string) ($member['name'] ?? '')) ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16"><path fill="currentColor" d="M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm0 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm0 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg>
                                </button>
                                <ul class="members-action-menu__list" role="menu" hidden>
                                    <?php if ($canActivate): ?>
                                    <li role="none"><button type="button" role="menuitem" class="members-action-menu__item" data-modal-action="activate" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>">Activate</button></li>
                                    <?php endif; ?>
                                    <?php if ($canSuspend): ?>
                                    <li role="none"><button type="button" role="menuitem" class="members-action-menu__item" data-modal-action="suspend" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>">Suspend</button></li>
                                    <?php endif; ?>
                                    <?php if ($canTerminate): ?>
                                    <li role="none"><button type="button" role="menuitem" class="members-action-menu__item members-action-menu__item--danger" data-modal-action="terminate" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>">Terminate</button></li>
                                    <?php endif; ?>
                                    <?php if ($canActivate || $canSuspend || $canTerminate): ?>
                                    <li role="separator" class="members-action-menu__separator"></li>
                                    <?php endif; ?>
                                    <li role="none"><button type="button" role="menuitem" class="members-action-menu__item" data-modal-action="audit" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" data-audit-url="/backstage/members<?= $buildQuery(['page' => $page, 'audit' => $memberId]) ?>">Audit log</button></li>
                                    <li role="separator" class="members-action-menu__separator"></li>
                                    <li role="none"><a role="menuitem" class="members-action-menu__item" href="/backstage/governance/expulsions/new?target_user_id=<?= rawurlencode($memberId) ?>">Ehdota erottamista</a></li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="members-pagination">
            <?php if ($page > 1): ?>
                <a href="/backstage/members<?= $buildQuery(['page' => $page - 1, 'audit' => $auditFor]) ?>" class="btn btn--ghost btn--sm">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="/backstage/members<?= $buildQuery(['page' => $page + 1, 'audit' => $auditFor]) ?>" class="btn btn--ghost btn--sm">Next</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isGsa): ?>
<!-- Section: Action modals for member status and audit -->
<div class="members-modal-backdrop" id="members-modal-activate" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-activate-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-activate-title">Activate Member</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Member: <strong data-field="member-name">-</strong></p>
            <form method="post" action="/backstage/members<?= $buildQuery(['page' => $page, 'audit' => $auditFor]) ?>" class="members-modal__form">
                <input type="hidden" name="member_id" data-field="member-id" value="">
                <input type="hidden" name="status" value="active">
                <label class="members-field">
                    <span class="members-label">Note / reason</span>
                    <textarea name="reason" rows="4" required placeholder="Write reason for activation"></textarea>
                </label>
                <div class="members-modal__footer">
                    <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn--primary">Activate</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="members-modal-backdrop" id="members-modal-suspend" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-suspend-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-suspend-title">Suspend Member</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Member: <strong data-field="member-name">-</strong></p>
            <form method="post" action="/backstage/members<?= $buildQuery(['page' => $page, 'audit' => $auditFor]) ?>" class="members-modal__form">
                <input type="hidden" name="member_id" data-field="member-id" value="">
                <input type="hidden" name="status" value="suspended">
                <label class="members-field">
                    <span class="members-label">Note / reason</span>
                    <textarea name="reason" rows="4" required placeholder="Write reason for suspension"></textarea>
                </label>
                <div class="members-modal__footer">
                    <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn--primary">Suspend</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="members-modal-backdrop" id="members-modal-terminate" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-terminate-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-terminate-title">Terminate Member</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Member: <strong data-field="member-name">-</strong></p>
            <form method="post" action="/backstage/members<?= $buildQuery(['page' => $page, 'audit' => $auditFor]) ?>" class="members-modal__form">
                <input type="hidden" name="member_id" data-field="member-id" value="">
                <input type="hidden" name="status" value="terminated">
                <label class="members-field">
                    <span class="members-label">Note / reason</span>
                    <textarea name="reason" rows="4" required placeholder="Write reason for termination"></textarea>
                </label>
                <div class="members-modal__footer">
                    <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn members-btn-danger">Terminate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="members-modal-backdrop" id="members-modal-audit" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-audit-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-audit-title">Member Audit</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Open audit log for <strong data-field="member-name">-</strong></p>
            <div class="members-modal__footer">
                <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                <a href="/backstage/members<?= $buildQuery(['page' => $page]) ?>" class="btn btn--primary" data-field="audit-link">Open Audit</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($auditFor !== ''): ?>
<div class="card members-audit-card">
    <div class="card__body">
        <div class="members-audit-header">
            <h2 class="card__title">Audit Log</h2>
            <a class="btn btn--ghost btn--sm" href="/backstage/members<?= $buildQuery(['page' => $page]) ?>">Close</a>
        </div>
        <?php if (!$auditRows): ?>
            <p class="members-empty-text">No audit entries found for this member.</p>
        <?php else: ?>
            <div class="members-audit-wrap">
                <table class="data-table members-audit-table">
                    <thead>
                        <tr>
                            <th>Changed At</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Changed By</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auditRows as $row): ?>
                        <tr>
                            <td><?= $esc((string) ($row['changed_at'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['from_status'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['to_status'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['changed_by_name'] ?? 'Unknown')) ?></td>
                            <td><?= $esc((string) ($row['reason'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php elseif ($view === 'pending'): ?>
<!-- ============================================================ Pending applications -->

<?php if ($inviteUrl !== null): ?>
<div class="pending-app-toast pending-app-toast--success" style="position:static;animation:none;margin-bottom:var(--space-4);cursor:default;" id="invite-success-banner">
    <div class="pending-app-toast__title">Application approved — invite link (valid 7 days)</div>
    <?php if ($assignedNumber !== null && $assignedNumberLabel !== null): ?>
    <div class="pending-app-toast__meta"><?= htmlspecialchars($assignedNumberLabel, ENT_QUOTES, 'UTF-8') ?>: <strong><?= htmlspecialchars($assignedNumber, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <?php endif; ?>
    <?php if ($inviteExpiresAt !== null): ?>
    <div class="pending-app-toast__meta">Expires: <?= htmlspecialchars($inviteExpiresAt, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div style="margin-top:8px;word-break:break-all;">
        <a href="<?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?>" id="invite-url-link" target="_blank" rel="noopener"><?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?></a>
    </div>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button type="button" id="copy-invite" style="padding:4px 12px;border:1px solid currentColor;border-radius:4px;background:transparent;cursor:pointer;font-size:13px;">Copy link</button>
        <button type="button" id="email-invite" style="padding:4px 12px;border:1px solid currentColor;border-radius:4px;background:transparent;cursor:pointer;font-size:13px;">Send via email</button>
        <span id="email-invite-status" style="font-size:13px;color:var(--status-success);display:none;"></span>
    </div>
</div>
<script>
(function () {
    var copyBtn = document.getElementById('copy-invite');
    var emailBtn = document.getElementById('email-invite');
    var statusEl = document.getElementById('email-invite-status');
    var url = <?= json_encode($inviteUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (!copyBtn || !url) { return; }
    copyBtn.addEventListener('click', function () {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                copyBtn.textContent = 'Copied!';
                setTimeout(function () { copyBtn.textContent = 'Copy link'; }, 2000);
            }).catch(function () {
                prompt('Copy this invite link:', url);
            });
        } else {
            prompt('Copy this invite link:', url);
        }
    });
    if (emailBtn && statusEl) {
        emailBtn.addEventListener('click', function () {
            emailBtn.disabled = true;
            emailBtn.textContent = 'Sending…';
            // TODO: replace with real POST to /api/auth/send-invite-email when Mailu is deployed.
            setTimeout(function () {
                emailBtn.textContent = 'Sent';
                statusEl.style.display = 'inline';
                statusEl.textContent = 'Invite link sent to the applicant (mail delivery not yet wired — shown here for UX).';
            }, 400);
        });
    }
})();
</script>
<?php endif; ?>

<div class="card members-table-card">
    <div class="card__body members-table-body">
        <div class="members-filters-row">
            <strong>Pending applications: <?= $totalPending ?></strong>
            <span class="members-filters-row__meta">click a row to open</span>
        </div>
        <table class="data-table members-table members-applist-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Name / Organization</th>
                    <th>Email</th>
                    <th>Country</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$memberPending && !$supporterPending): ?>
                <tr><td colspan="6" class="members-empty">No pending applications.</td></tr>
            <?php else: ?>
                <?php foreach ($memberPending as $app):
                    $appId = (string) ($app['id'] ?? '');
                    $detailHref = '/backstage/members?detail=' . rawurlencode($appId) . '&kind=member';
                ?>
                    <tr id="app-<?= $esc($appId) ?>" class="members-applist-row" data-href="<?= $esc($detailHref) ?>">
                        <td><span class="pill pill--scheduled">Member</span></td>
                        <td><?= $esc((string) ($app['name'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['email'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['country'] ?? '-')) ?></td>
                        <td><?= $esc((string) (substr((string) ($app['created_at'] ?? ''), 0, 10) ?: '-')) ?></td>
                        <td><a class="members-applist-row__open" href="<?= $esc($detailHref) ?>">Open →</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($supporterPending as $app):
                    $appId = (string) ($app['id'] ?? '');
                    $detailHref = '/backstage/members?detail=' . rawurlencode($appId) . '&kind=supporter';
                ?>
                    <tr id="app-<?= $esc($appId) ?>" class="members-applist-row" data-href="<?= $esc($detailHref) ?>">
                        <td><span class="pill pill--featured">Supporter</span></td>
                        <td><?= $esc((string) ($app['org_name'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['email'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['country'] ?? '-')) ?></td>
                        <td><?= $esc((string) (substr((string) ($app['created_at'] ?? ''), 0, 10) ?: '-')) ?></td>
                        <td><a class="members-applist-row__open" href="<?= $esc($detailHref) ?>">Open →</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif ($view === 'approved' || $view === 'rejected'): ?>
<!-- ============================================================ Decided applications (read-only table) -->
<?php
$decidedTotal = count($decidedMember) + count($decidedSupporter);
$decidedTitle = $view === 'approved' ? 'Approved' : 'Rejected';
?>
<div class="card members-table-card">
    <div class="card__body members-table-body">
        <div class="members-filters-row">
            <strong><?= $esc($decidedTitle) ?> applications (last 30 days): <?= $decidedTotal ?></strong>
            <span class="members-filters-row__meta">click a row to open</span>
        </div>
        <table class="data-table members-table members-applist-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Name / Organization</th>
                    <th>Email</th>
                    <th>Country</th>
                    <th>Decided</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$decidedMember && !$decidedSupporter): ?>
                <tr><td colspan="6" class="members-empty">No <?= $esc(strtolower($decidedTitle)) ?> applications in the last 30 days.</td></tr>
            <?php else: ?>
                <?php foreach ($decidedMember as $app):
                    $appId = (string) ($app['id'] ?? '');
                    $detailHref = '/backstage/members?detail=' . rawurlencode($appId) . '&kind=member';
                ?>
                    <tr class="members-applist-row" data-href="<?= $esc($detailHref) ?>">
                        <td><span class="pill pill--scheduled">Member</span></td>
                        <td><?= $esc((string) ($app['name'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['email'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['country'] ?? '-')) ?></td>
                        <td><?= $esc((string) (substr((string) ($app['decided_at'] ?? ''), 0, 10) ?: '-')) ?></td>
                        <td><a class="members-applist-row__open" href="<?= $esc($detailHref) ?>">Open →</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($decidedSupporter as $app):
                    $appId = (string) ($app['id'] ?? '');
                    $detailHref = '/backstage/members?detail=' . rawurlencode($appId) . '&kind=supporter';
                ?>
                    <tr class="members-applist-row" data-href="<?= $esc($detailHref) ?>">
                        <td><span class="pill pill--featured">Supporter</span></td>
                        <td><?= $esc((string) ($app['org_name'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['email'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($app['country'] ?? '-')) ?></td>
                        <td><?= $esc((string) (substr((string) ($app['decided_at'] ?? ''), 0, 10) ?: '-')) ?></td>
                        <td><a class="members-applist-row__open" href="<?= $esc($detailHref) ?>">Open →</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var params = new URLSearchParams(window.location.search);
    var highlightId = params.get('highlight');
    if (!highlightId) { return; }
    var el = document.getElementById('app-' + highlightId);
    if (!el) { return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.style.outline = '3px solid var(--brand-primary)';
    el.style.outlineOffset = '3px';
    setTimeout(function () {
        el.style.outline = '';
        el.style.outlineOffset = '';
    }, 2000);
})();
</script>

<?php endif; // end detail-mode wrapper ?>
</div>

<script>
(function () {
    // Section: per-row action menu (Register tab only) — close other open menus
    // when one opens, and close on outside-click / Escape.
    const allMenus = document.querySelectorAll('.members-action-menu');
    const closeAllMenus = function () {
        allMenus.forEach(function (menu) {
            const trigger = menu.querySelector('.members-action-menu__trigger');
            const list = menu.querySelector('.members-action-menu__list');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
            if (list) list.hidden = true;
        });
    };
    allMenus.forEach(function (menu) {
        const trigger = menu.querySelector('.members-action-menu__trigger');
        const list = menu.querySelector('.members-action-menu__list');
        if (!trigger || !list) return;
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = !list.hidden;
            closeAllMenus();
            if (!isOpen) {
                trigger.setAttribute('aria-expanded', 'true');
                list.hidden = false;
            }
        });
    });
    document.addEventListener('click', closeAllMenus);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllMenus(); });

    // Section: per-action modals (status change + audit)
    const modalByAction = {
        activate: document.getElementById('members-modal-activate'),
        suspend: document.getElementById('members-modal-suspend'),
        terminate: document.getElementById('members-modal-terminate'),
        audit: document.getElementById('members-modal-audit')
    };

    let currentModal = null;

    const closeModal = function () {
        if (!currentModal) {
            return;
        }
        currentModal.hidden = true;
        currentModal.setAttribute('aria-hidden', 'true');
        currentModal = null;
    };

    const openModal = function (action, trigger) {
        const modal = modalByAction[action];
        if (!modal) {
            return;
        }

        const memberId = trigger.getAttribute('data-member-id') || '';
        const memberName = trigger.getAttribute('data-member-name') || 'Unknown';
        const auditUrl = trigger.getAttribute('data-audit-url') || '/backstage/members';

        modal.querySelectorAll('[data-field="member-name"]').forEach(function (el) {
            el.textContent = memberName;
        });
        modal.querySelectorAll('[data-field="member-id"]').forEach(function (el) {
            el.value = memberId;
        });

        const auditLink = modal.querySelector('[data-field="audit-link"]');
        if (auditLink) {
            auditLink.setAttribute('href', auditUrl);
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        currentModal = modal;

        const focusTarget = modal.querySelector('textarea, button, a');
        if (focusTarget) {
            focusTarget.focus();
        }
    };

    document.querySelectorAll('.members-action-menu__item[data-modal-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeAllMenus();
            openModal(btn.getAttribute('data-modal-action') || '', btn);
        });
    });

    // Section: clickable rows in pending/decided application tables
    document.querySelectorAll('.members-applist-row[data-href]').forEach(function (row) {
        row.addEventListener('click', function (e) {
            // Allow direct clicks on the explicit "Open" link to behave normally.
            if (e.target.closest('a')) return;
            const href = row.getAttribute('data-href');
            if (href) window.location.href = href;
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    Object.values(modalByAction).forEach(function (modal) {
        if (!modal) {
            return;
        }
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
})();
</script>

<?php
$pageContent = ob_get_clean();
require DAEMS_SITE_PUBLIC . '/pages/layout.php';
