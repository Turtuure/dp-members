<?php
/**
 * Legacy /backstage/applications URL — redirected to the merged
 * /backstage/members?view=pending page (see plan
 * docs/superpowers/plans/2026-04-27-applications-members-merge.md).
 */
declare(strict_types=1);

header('Location: /backstage/members?view=pending', true, 302);
exit;
