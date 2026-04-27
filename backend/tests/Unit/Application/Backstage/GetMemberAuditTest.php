<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\GetMemberAudit\GetMemberAudit;
use DaemsModule\Members\Application\Backstage\GetMemberAudit\GetMemberAuditInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Backstage\MemberStatusAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class GetMemberAuditTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: false, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testNonAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new GetMemberAudit($repo))->execute(
            new GetMemberAuditInput($this->acting(UserTenantRole::Member), UserId::generate()->value()),
        );
    }

    public function testAdminGetsEntries(): void
    {
        $entry = new MemberStatusAuditEntry('id-1', 'active', 'suspended', 'reason', 'Admin Name', '2026-04-20 12:00:00');
        $repo  = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->method('getAuditEntriesForMember')->willReturn([$entry]);

        $out = (new GetMemberAudit($repo))->execute(
            new GetMemberAuditInput($this->acting(UserTenantRole::Admin), UserId::generate()->value()),
        );

        self::assertCount(1, $out->entries);
        self::assertSame('suspended', $out->entries[0]->newStatus);
    }
}
