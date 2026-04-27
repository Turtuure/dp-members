<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ListMembers\ListMembers;
use DaemsModule\Members\Application\Backstage\ListMembers\ListMembersInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ListMembersTest extends TestCase
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
        (new ListMembers($repo))->execute(new ListMembersInput($this->acting(UserTenantRole::Member)));
    }

    public function testReturnsEntriesWithPaginationMeta(): void
    {
        $entry = new MemberDirectoryEntry('uid', 'Alice', 'a@x', 'individual', 'active', '1001', 'member', '2026-01-01 00:00:00', null, null, '2026-01-01 00:00:00');

        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->method('listMembersForTenant')->willReturn(['entries' => [$entry], 'total' => 1]);

        $out = (new ListMembers($repo))->execute(new ListMembersInput($this->acting(UserTenantRole::Admin)));

        self::assertCount(1, $out->entries);
        self::assertSame(1, $out->total);
        self::assertSame(1, $out->page);
        self::assertSame(50, $out->perPage);
    }

    public function testPerPageClampedToMax200(): void
    {
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->expects($this->once())->method('listMembersForTenant')
             ->with(self::anything(), self::anything(), self::anything(), self::anything(), 1, 200)
             ->willReturn(['entries' => [], 'total' => 0]);

        (new ListMembers($repo))->execute(
            new ListMembersInput($this->acting(UserTenantRole::Admin), perPage: 99999),
        );
    }
}
