<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Forum\ForumReport;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;

final class ListPendingApplicationsForAdmin
{
    private const CAP = 50;

    public function __construct(
        private readonly MemberApplicationRepositoryInterface $members,
        private readonly SupporterApplicationRepositoryInterface $supporters,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
        private readonly ProjectProposalRepositoryInterface $proposals,
        private readonly ForumReportRepositoryInterface $forumReports,
        private readonly ForumRepositoryInterface $forum,
    ) {}

    public function execute(ListPendingApplicationsForAdminInput $input): ListPendingApplicationsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $dismissed = array_flip(
            $this->dismissals->listAppIdsDismissedByAdmin($input->acting->id->value())
        );

        /** @var list<array{id:string,type:string,name:string,created_at:string}> $items */
        $items = [];

        foreach ($this->members->listPendingForTenant($tenantId, PHP_INT_MAX) as $app) {
            if (isset($dismissed[$app->id()->value()])) {
                continue;
            }
            $items[] = [
                'id'         => $app->id()->value(),
                'type'       => 'member',
                'name'       => $app->name(),
                'created_at' => $app->createdAt() ?? '',
            ];
        }

        foreach ($this->supporters->listPendingForTenant($tenantId, PHP_INT_MAX) as $app) {
            if (isset($dismissed[$app->id()->value()])) {
                continue;
            }
            $items[] = [
                'id'         => $app->id()->value(),
                'type'       => 'supporter',
                'name'       => $app->contactPerson(),
                'created_at' => $app->createdAt() ?? '',
            ];
        }

        foreach ($this->proposals->listPendingForTenant($tenantId) as $proposal) {
            if (isset($dismissed[$proposal->id()->value()])) {
                continue;
            }
            $items[] = [
                'id'         => $proposal->id()->value(),
                'type'       => 'project_proposal',
                'name'       => $proposal->title(),
                'created_at' => $proposal->createdAt(),
            ];
        }

        $forumAggregated = $this->forumReports->listAggregatedForTenant(
            $tenantId,
            ['status' => ForumReport::STATUS_OPEN],
        );
        foreach ($forumAggregated as $agg) {
            $compoundId = $agg->compoundKey();
            if (isset($dismissed[$compoundId])) {
                continue;
            }
            if ($agg->targetType === 'post') {
                $p = $this->forum->findPostByIdForTenant($agg->targetId, $tenantId);
                $name = $p !== null ? mb_substr(trim($p->content()), 0, 80) : '(deleted post)';
            } else {
                $t = $this->forum->findTopicByIdForTenant($agg->targetId, $tenantId);
                $name = $t !== null ? $t->title() : '(deleted topic)';
            }
            $items[] = [
                'id'         => $compoundId,
                'type'       => 'forum_report',
                'name'       => $name,
                'created_at' => $agg->latestCreatedAt,
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        $total = count($items);

        return new ListPendingApplicationsForAdminOutput(
            array_slice($items, 0, self::CAP),
            $total,
        );
    }
}
