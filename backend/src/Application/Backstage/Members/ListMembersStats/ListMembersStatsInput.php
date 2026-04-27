<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\Members\ListMembersStats;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Tenant\TenantId;

final class ListMembersStatsInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly TenantId $tenantId,
    ) {}
}
