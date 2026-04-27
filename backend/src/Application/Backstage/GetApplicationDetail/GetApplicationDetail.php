<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\GetApplicationDetail;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use InvalidArgumentException;

/**
 * Single-application detail fetch for the Members admin detail page.
 * Returns full submitted data + decision metadata. Tenant-scoped.
 */
final class GetApplicationDetail
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
    ) {}

    public function execute(GetApplicationDetailInput $input): GetApplicationDetailOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        if ($input->type === 'member') {
            $row = $this->memberApps->findDetailedByIdForTenant($input->id, $tenantId);
        } elseif ($input->type === 'supporter') {
            $row = $this->supporterApps->findDetailedByIdForTenant($input->id, $tenantId);
        } else {
            throw new InvalidArgumentException("type must be 'member' or 'supporter', got: {$input->type}");
        }

        if ($row === null) {
            throw new NotFoundException('application_not_found');
        }

        return new GetApplicationDetailOutput(type: $input->type, application: $row);
    }
}
