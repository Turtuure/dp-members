<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListApplicationsStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
