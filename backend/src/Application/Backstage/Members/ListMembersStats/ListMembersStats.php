<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\Members\ListMembersStats;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;

final class ListMembersStats
{
    public function __construct(
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly MemberStatusAuditRepositoryInterface $audit,
    ) {}

    public function execute(ListMembersStatsInput $input): ListMembersStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }

        $stats = $this->userTenants->membershipStatsForTenant($input->tenantId);
        $stats['inactive']['sparkline'] = $this->audit->dailyTransitionsForTenant($input->tenantId, 'inactive');

        return new ListMembersStatsOutput(stats: $stats);
    }
}
