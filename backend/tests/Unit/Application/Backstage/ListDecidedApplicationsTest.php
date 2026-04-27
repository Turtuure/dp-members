<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ListDecidedApplications\ListDecidedApplications;
use DaemsModule\Members\Application\Backstage\ListDecidedApplications\ListDecidedApplicationsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ListDecidedApplicationsTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function acting(?UserTenantRole $role, bool $platformAdmin = false): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: $platformAdmin, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testThrowsForbiddenWhenNonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $memberRepo    = $this->createMock(MemberApplicationRepositoryInterface::class);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new ListDecidedApplications($memberRepo, $supporterRepo))->execute(
            new ListDecidedApplicationsInput($this->acting(UserTenantRole::Member), 'rejected'),
        );
    }

    public function testReturnsRejectedSplitAcrossMemberAndSupporter(): void
    {
        $memberRow = [
            'id'            => 'mem-1',
            'name'          => 'Alice',
            'email'         => 'a@x.com',
            'date_of_birth' => '1990-01-01',
            'country'       => 'FI',
            'motivation'    => 'motive',
            'decided_at'    => '2026-04-25 10:00:00',
            'decision_note' => 'duplicate application',
        ];
        $supporterRow = [
            'id'             => 'sup-1',
            'org_name'       => 'OrgX',
            'contact_person' => 'Bob',
            'email'          => 'b@x.com',
            'country'        => 'FI',
            'motivation'     => 'sponsor us',
            'decided_at'     => '2026-04-26 09:00:00',
            'decision_note'  => null,
        ];

        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->expects(self::once())
            ->method('listDecidedForTenant')
            ->with($this->tenant, 'rejected', 200, 30)
            ->willReturn([$memberRow]);

        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->expects(self::once())
            ->method('listDecidedForTenant')
            ->with($this->tenant, 'rejected', 200, 30)
            ->willReturn([$supporterRow]);

        $out = (new ListDecidedApplications($memberRepo, $supporterRepo))->execute(
            new ListDecidedApplicationsInput($this->acting(UserTenantRole::Admin), 'rejected'),
        );

        self::assertSame('rejected', $out->decision);
        self::assertSame(30, $out->days);
        self::assertCount(1, $out->member);
        self::assertCount(1, $out->supporter);
        self::assertSame('Alice', $out->member[0]['name']);
        self::assertSame('OrgX', $out->supporter[0]['org_name']);

        $arr = $out->toArray();
        self::assertSame(2, $arr['total']);
        self::assertSame('rejected', $arr['decision']);
        self::assertSame(30, $arr['days']);
    }

    public function testForwardsApprovedDecisionAndCustomDaysLimit(): void
    {
        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->expects(self::once())
            ->method('listDecidedForTenant')
            ->with($this->tenant, 'approved', 50, 7)
            ->willReturn([]);

        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->expects(self::once())
            ->method('listDecidedForTenant')
            ->with($this->tenant, 'approved', 50, 7)
            ->willReturn([]);

        $out = (new ListDecidedApplications($memberRepo, $supporterRepo))->execute(
            new ListDecidedApplicationsInput(
                acting: $this->acting(UserTenantRole::Admin),
                decision: 'approved',
                limit: 50,
                days: 7,
            ),
        );

        self::assertSame('approved', $out->decision);
        self::assertSame(7, $out->days);
        self::assertSame([], $out->member);
        self::assertSame([], $out->supporter);
    }

    public function testPlatformAdminAllowedEvenWithoutTenantRole(): void
    {
        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->method('listDecidedForTenant')->willReturn([]);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->method('listDecidedForTenant')->willReturn([]);

        $out = (new ListDecidedApplications($memberRepo, $supporterRepo))->execute(
            new ListDecidedApplicationsInput(
                acting: $this->acting(role: null, platformAdmin: true),
                decision: 'rejected',
            ),
        );

        self::assertSame([], $out->member);
    }
}
