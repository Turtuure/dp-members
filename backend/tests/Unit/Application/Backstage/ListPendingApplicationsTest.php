<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplications;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplicationsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ListPendingApplicationsTest extends TestCase
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

        (new ListPendingApplications($memberRepo, $supporterRepo))->execute(
            new ListPendingApplicationsInput($this->acting(UserTenantRole::Member)),
        );
    }

    public function testReturnsPendingApplicationsForAdmin(): void
    {
        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->tenant,
            'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );

        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->method('listPendingForTenant')->willReturn([$app]);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->method('listPendingForTenant')->willReturn([]);

        $out = (new ListPendingApplications($memberRepo, $supporterRepo))->execute(
            new ListPendingApplicationsInput($this->acting(UserTenantRole::Admin)),
        );

        self::assertCount(1, $out->member);
        self::assertSame('Alice', $out->member[0]->name());
    }

    public function testPlatformAdminAllowedEvenWithoutTenantRole(): void
    {
        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->method('listPendingForTenant')->willReturn([]);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->method('listPendingForTenant')->willReturn([]);

        $out = (new ListPendingApplications($memberRepo, $supporterRepo))->execute(
            new ListPendingApplicationsInput($this->acting(role: null, platformAdmin: true)),
        );

        self::assertSame([], $out->member);
    }
}
