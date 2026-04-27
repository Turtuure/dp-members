<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\GetMemberAudit;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\User\UserId;

final class GetMemberAudit
{
    public function __construct(
        private readonly MemberDirectoryRepositoryInterface $directory,
    ) {}

    public function execute(GetMemberAuditInput $input): GetMemberAuditOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $entries = $this->directory->getAuditEntriesForMember(
            UserId::fromString($input->memberId), $tenantId, max(1, min(500, $input->limit)),
        );

        return new GetMemberAuditOutput($entries);
    }
}
