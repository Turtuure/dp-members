<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListMembers;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;

final class ListMembers
{
    public function __construct(
        private readonly MemberDirectoryRepositoryInterface $directory,
    ) {}

    public function execute(ListMembersInput $input): ListMembersOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $perPage = max(1, min(200, $input->perPage));
        $page    = max(1, $input->page);

        $result = $this->directory->listMembersForTenant(
            $tenantId, $input->filters, $input->sort, $input->dir, $page, $perPage,
        );

        return new ListMembersOutput(
            entries: $result['entries'],
            total:   $result['total'],
            page:    $page,
            perPage: $perPage,
        );
    }
}
