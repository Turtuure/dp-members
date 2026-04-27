<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\DismissApplication;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;

final class DismissApplication
{
    public function __construct(
        private readonly AdminApplicationDismissalRepositoryInterface $repo,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function execute(DismissApplicationInput $input): void
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        if (!in_array($input->appType, ['member', 'supporter', 'project_proposal', 'forum_report'], true)) {
            throw new ValidationException(['app_type' => 'invalid_value']);
        }

        if ($input->appType === 'forum_report'
            && preg_match('/^(post|topic):[0-9a-f\-]{36}$/', $input->appId) !== 1
        ) {
            throw new ValidationException(['app_id' => 'invalid_forum_report_id']);
        }

        $this->repo->save(new AdminApplicationDismissal(
            $this->ids->generate(),
            $input->acting->id->value(),
            $input->appId,
            $input->appType,
            $this->clock->now(),
        ));
    }
}
