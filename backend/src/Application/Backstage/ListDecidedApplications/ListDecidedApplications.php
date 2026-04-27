<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListDecidedApplications;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

/**
 * Lists decided (approved or rejected) member + supporter applications scoped
 * to the active tenant within the configurable lookback window. Powers the
 * /backstage/applications/decided endpoint behind the Members > Pending KPI
 * cards (Rejected / Approved) which act as filter triggers.
 */
final class ListDecidedApplications
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
    ) {}

    public function execute(ListDecidedApplicationsInput $input): ListDecidedApplicationsOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $member    = $this->memberApps->listDecidedForTenant($tenantId, $input->decision, $input->limit, $input->days);
        $supporter = $this->supporterApps->listDecidedForTenant($tenantId, $input->decision, $input->limit, $input->days);

        return new ListDecidedApplicationsOutput(
            member: $member,
            supporter: $supporter,
            decision: $input->decision,
            days: $input->days,
        );
    }
}
