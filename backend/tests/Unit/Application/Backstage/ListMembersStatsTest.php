<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\Members\ListMembersStats\ListMembersStats;
use DaemsModule\Members\Application\Backstage\Members\ListMembersStats\ListMembersStatsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\ActingUserFactory;
use DaemsModule\Members\Tests\Support\InMemoryMemberStatusAuditRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use PHPUnit\Framework\TestCase;

final class ListMembersStatsTest extends TestCase
{
    private const TENANT_ID = '019d0000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '019d0000-0000-7000-8000-000000000a01';
    private const USER_ID   = '019d0000-0000-7000-8000-000000000a02';
    private const MEMBER_A  = '019d0000-0000-7000-8000-000000000b01';
    private const MEMBER_B  = '019d0000-0000-7000-8000-000000000b02';
    private const SUPP_ID   = '019d0000-0000-7000-8000-000000000b03';
    private const AUDIT_ID  = '019d0000-0000-7000-8000-000000000c01';

    public function test_assembles_4_kpis_with_inactive_sparkline_from_audit_repo(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        // Seed user-tenant memberships: 2 members + 1 supporter.
        $userTenants = new InMemoryUserTenantRepository();
        $userTenants->attach(UserId::fromString(self::MEMBER_A), $tenantId, UserTenantRole::Member);
        $userTenants->attach(UserId::fromString(self::MEMBER_B), $tenantId, UserTenantRole::Member);
        $userTenants->attach(UserId::fromString(self::SUPP_ID),  $tenantId, UserTenantRole::Supporter);

        // Seed an inactive transition for today â€” proves the use case wires the audit repo
        // into stats[inactive][sparkline] (replacing the empty default from the user-tenant fake).
        $audit  = new InMemoryMemberStatusAuditRepository();
        $today  = new \DateTimeImmutable('today');
        $audit->save(new MemberStatusAudit(
            id:                  self::AUDIT_ID,
            tenantId:            $tenantId->value(),
            userId:              self::MEMBER_A,
            previousStatus:      'active',
            newStatus:           'inactive',
            reason:              'test',
            performedByAdminId:  self::ADMIN_ID,
            createdAt:           $today,
        ));

        $usecase = new ListMembersStats($userTenants, $audit);
        $out     = $usecase->execute(new ListMembersStatsInput(acting: $admin, tenantId: $tenantId));

        // Stats shape: 4 KPIs each with value + sparkline.
        self::assertSame(3, $out->stats['total_members']['value']);
        self::assertSame(3, $out->stats['new_members']['value']);
        self::assertSame(1, $out->stats['supporters']['value']);
        self::assertSame(0, $out->stats['inactive']['value']);

        // The inactive sparkline must come from the audit repo (30 zero-filled entries
        // with today's bucket bumped to 1 by our seeded audit row).
        $inactiveSeries = $out->stats['inactive']['sparkline'];
        self::assertCount(30, $inactiveSeries);
        $todayKey  = $today->format('Y-m-d');
        $todayBucket = $inactiveSeries[29];
        self::assertSame($todayKey, $todayBucket['date']);
        self::assertSame(1, $todayBucket['value']);
    }

    public function test_throws_forbidden_for_non_admin(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $member   = ActingUserFactory::registeredInTenant(self::USER_ID, $tenantId);

        $this->expectException(ForbiddenException::class);

        $usecase = new ListMembersStats(
            new InMemoryUserTenantRepository(),
            new InMemoryMemberStatusAuditRepository(),
        );
        $usecase->execute(new ListMembersStatsInput(acting: $member, tenantId: $tenantId));
    }
}
