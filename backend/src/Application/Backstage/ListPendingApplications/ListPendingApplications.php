<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

final class ListPendingApplications
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
    ) {}

    public function execute(ListPendingApplicationsInput $input): ListPendingApplicationsOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $memberApps    = $this->memberApps->listPendingForTenant($tenantId, $input->limit);
        $supporterApps = $this->supporterApps->listPendingForTenant($tenantId, $input->limit);

        return new ListPendingApplicationsOutput(member: $memberApps, supporter: $supporterApps);
    }
}
