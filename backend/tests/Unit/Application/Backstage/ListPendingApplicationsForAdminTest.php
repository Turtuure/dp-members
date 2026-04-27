<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplication;
use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplicationInput;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationId;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Members\Tests\Support\InMemoryAdminApplicationDismissalRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberApplicationRepository;
use DaemsModule\Projects\Tests\Support\InMemoryProjectProposalRepository;
use DaemsModule\Members\Tests\Support\InMemorySupporterApplicationRepository;
use DaemsModule\Forum\Tests\Support\ForumSeed;
use DaemsModule\Forum\Tests\Support\InMemoryForumReportRepository;
use DaemsModule\Forum\Tests\Support\InMemoryForumRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ListPendingApplicationsForAdminTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function adminActingUser(string $userId = '01958000-0000-7000-8000-000000000010'): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString($userId),
            email: 'admin@x.com',
            isPlatformAdmin: false,
            activeTenant: $this->tenant,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function nonAdminActingUser(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString('01958000-0000-7000-8000-000000000011'),
            email: 'member@x.com',
            isPlatformAdmin: false,
            activeTenant: $this->tenant,
            roleInActiveTenant: UserTenantRole::Member,
        );
    }

    private function makeMemberApp(string $id, string $name = 'Member', string $createdAt = '2026-04-01 10:00:00'): MemberApplication
    {
        return new MemberApplication(
            MemberApplicationId::fromString($id),
            $this->tenant,
            $name,
            strtolower($name) . '@x.test',
            '1990-01-01',
            null,
            'motivation',
            null,
            'pending',
            $createdAt,
        );
    }

    private function makeSupporterApp(string $id, string $contactPerson = 'Org Contact', string $createdAt = '2026-04-02 10:00:00'): SupporterApplication
    {
        return new SupporterApplication(
            SupporterApplicationId::fromString($id),
            $this->tenant,
            'Org Name',
            $contactPerson,
            null,
            strtolower(str_replace(' ', '', $contactPerson)) . '@org.test',
            null,
            'motivation',
            null,
            'pending',
            $createdAt,
        );
    }

    private function makeProposal(string $id, string $title = 'Community Garden', string $createdAt = '2026-04-20 10:00:00'): ProjectProposal
    {
        return new ProjectProposal(
            ProjectProposalId::fromString($id),
            $this->tenant,
            '01958000-0000-7000-8000-0000000000aa',
            'Proposer Name',
            'proposer@x.test',
            $title,
            'environment',
            'A summary for the proposal',
            'A description for the proposal',
            'pending',
            $createdAt,
        );
    }

    private function makeClock(): \Daems\Domain\Shared\Clock
    {
        return new class implements \Daems\Domain\Shared\Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-04-20 12:00:00');
            }
        };
    }

    private function makeIds(string $id = 'd-1'): \Daems\Domain\Shared\IdGeneratorInterface
    {
        return new class($id) implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function __construct(private readonly string $id) {}
            public function generate(): string { return $this->id; }
        };
    }

    public function test_merges_member_and_supporter_excludes_dismissed(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $proposalRepo  = new InMemoryProjectProposalRepository();
        $forumReports  = new InMemoryForumReportRepository();
        $forum         = new InMemoryForumRepository();

        $member1 = $this->makeMemberApp('01958000-0000-7000-8000-000000000021', 'Alice');
        $member2 = $this->makeMemberApp('01958000-0000-7000-8000-000000000022', 'Bob');
        $member3 = $this->makeMemberApp('01958000-0000-7000-8000-000000000023', 'Carol');
        $supporter1 = $this->makeSupporterApp('01958000-0000-7000-8000-000000000024', 'Dana');
        $supporter2 = $this->makeSupporterApp('01958000-0000-7000-8000-000000000025', 'Eve');

        $memberApps->save($member1);
        $memberApps->save($member2);
        $memberApps->save($member3);
        $supporterApps->save($supporter1);
        $supporterApps->save($supporter2);

        // Admin dismisses member3
        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');
        $dismisser = new DismissApplication($dismissals, $this->makeClock(), $this->makeIds('dis-1'));
        $dismisser->execute(new DismissApplicationInput($acting, '01958000-0000-7000-8000-000000000023', 'member'));

        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo, $forumReports, $forum);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        self::assertSame(4, $out->total);
        self::assertCount(4, $out->items);

        $ids = array_column($out->items, 'id');
        self::assertContains('01958000-0000-7000-8000-000000000021', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000022', $ids);
        self::assertNotContains('01958000-0000-7000-8000-000000000023', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000024', $ids);
        self::assertContains('01958000-0000-7000-8000-000000000025', $ids);

        // created_at is propagated from entities
        foreach ($out->items as $item) {
            self::assertNotSame('', $item['created_at'], "created_at should be non-empty for item {$item['id']}");
        }
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);

        $sut = new ListPendingApplicationsForAdmin(
            new InMemoryMemberApplicationRepository(),
            new InMemorySupporterApplicationRepository(),
            new InMemoryAdminApplicationDismissalRepository(),
            new InMemoryProjectProposalRepository(),
            new InMemoryForumReportRepository(),
            new InMemoryForumRepository(),
        );
        $sut->execute(new ListPendingApplicationsForAdminInput($this->nonAdminActingUser()));
    }

    public function test_output_includes_pending_project_proposals(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $proposalRepo  = new InMemoryProjectProposalRepository();
        $forumReports  = new InMemoryForumReportRepository();
        $forum         = new InMemoryForumRepository();

        $proposal = $this->makeProposal('01959900-0000-7000-8000-0000000000aa', 'Community Garden');
        $proposalRepo->save($proposal);

        $acting = $this->adminActingUser();
        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo, $forumReports, $forum);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        self::assertSame(1, $out->total);
        self::assertCount(1, $out->items);
        $item = $out->items[0];
        self::assertSame('01959900-0000-7000-8000-0000000000aa', $item['id']);
        self::assertSame('project_proposal', $item['type']);
        self::assertSame('Community Garden', $item['name']);
        self::assertSame('2026-04-20 10:00:00', $item['created_at']);
    }

    public function test_dismissed_proposal_is_excluded(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $proposalRepo  = new InMemoryProjectProposalRepository();
        $forumReports  = new InMemoryForumReportRepository();
        $forum         = new InMemoryForumRepository();

        $proposalId = '01959900-0000-7000-8000-0000000000bb';
        $proposalRepo->save($this->makeProposal($proposalId, 'Dismissed Proposal'));

        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');

        $dismisser = new DismissApplication($dismissals, $this->makeClock(), $this->makeIds('dis-proposal'));
        $dismisser->execute(new DismissApplicationInput($acting, $proposalId, 'project_proposal'));

        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo, $forumReports, $forum);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        self::assertSame(0, $out->total);
        self::assertSame([], $out->items);
    }

    public function test_includes_aggregated_forum_reports(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $proposalRepo  = new InMemoryProjectProposalRepository();
        $forumReports  = new InMemoryForumReportRepository();
        $forum         = new InMemoryForumRepository();

        $postId  = '01958000-0000-7000-8000-0000000a0001';
        $topicId = '01958000-0000-7000-8000-000000010001';

        ForumSeed::seedTopic($forum, $this->tenant, $topicId, 'topic-one', 'Offending Topic Title');
        ForumSeed::seedPost(
            $forum,
            $this->tenant,
            $postId,
            $topicId,
            'This is the offending post content that will be trimmed to 80 chars if longer...',
        );

        // Two reports on the same post, one on the topic â†’ 2 aggregated entries.
        $forumReports->seedOpen(
            $this->tenant,
            'post',
            $postId,
            '01958000-0000-7000-8000-0000000b0001',
            'spam',
        );
        $forumReports->seedOpen(
            $this->tenant,
            'post',
            $postId,
            '01958000-0000-7000-8000-0000000b0002',
            'harassment',
        );
        $forumReports->seedOpen(
            $this->tenant,
            'topic',
            $topicId,
            '01958000-0000-7000-8000-0000000b0003',
            'hate_speech',
        );

        $acting = $this->adminActingUser();
        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo, $forumReports, $forum);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        $forumItems = array_values(array_filter(
            $out->items,
            static fn (array $item): bool => $item['type'] === 'forum_report',
        ));

        self::assertCount(2, $forumItems, 'expected exactly 2 aggregated forum_report items (one per target)');

        $byId = [];
        foreach ($forumItems as $item) {
            $byId[$item['id']] = $item;
        }

        self::assertArrayHasKey('post:' . $postId, $byId);
        self::assertArrayHasKey('topic:' . $topicId, $byId);

        self::assertSame('forum_report', $byId['post:' . $postId]['type']);
        self::assertStringStartsWith('This is the offending post content', $byId['post:' . $postId]['name']);
        self::assertLessThanOrEqual(80, mb_strlen($byId['post:' . $postId]['name']));

        self::assertSame('forum_report', $byId['topic:' . $topicId]['type']);
        self::assertSame('Offending Topic Title', $byId['topic:' . $topicId]['name']);
    }

    public function test_dismissed_forum_report_excluded(): void
    {
        $memberApps    = new InMemoryMemberApplicationRepository();
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $proposalRepo  = new InMemoryProjectProposalRepository();
        $forumReports  = new InMemoryForumReportRepository();
        $forum         = new InMemoryForumRepository();

        $postId = '01958000-0000-7000-8000-0000000a0001';
        ForumSeed::seedPost($forum, $this->tenant, $postId, null, 'offending post');

        $forumReports->seedOpen(
            $this->tenant,
            'post',
            $postId,
            '01958000-0000-7000-8000-0000000b0001',
            'spam',
        );

        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');
        $compoundId = 'post:' . $postId;

        $dismisser = new DismissApplication($dismissals, $this->makeClock(), $this->makeIds('dis-fr'));
        $dismisser->execute(new DismissApplicationInput($acting, $compoundId, 'forum_report'));

        $sut = new ListPendingApplicationsForAdmin($memberApps, $supporterApps, $dismissals, $proposalRepo, $forumReports, $forum);
        $out = $sut->execute(new ListPendingApplicationsForAdminInput($acting));

        $ids = array_column($out->items, 'id');
        self::assertNotContains($compoundId, $ids, 'dismissed forum_report must be excluded from output');
    }
}
