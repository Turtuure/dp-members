<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use DaemsModule\Members\Application\Backstage\ChangeMemberStatus\ChangeMemberStatusInput;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChangeMemberStatusTest extends TestCase
{
    private TenantId $tenant;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->clock = new class implements Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-04-20 12:00:00'); }
        };
    }

    private function makeAnonymiseStub(): AnonymiseAccount
    {
        // AnonymiseAccount is final; build a real instance with InMemory fakes.
        // These tests never hit the 'terminated' branch, so execute() is never called.
        return new AnonymiseAccount(
            new \Daems\Tests\Support\Fake\InMemoryUserRepository(),
            new \Daems\Tests\Support\Fake\InMemoryUserTenantRepository(),
            new \Daems\Tests\Support\Fake\InMemoryAuthTokenRepository(),
            new \DaemsModule\Members\Tests\Support\InMemoryMemberStatusAuditRepository(),
            new \Daems\Tests\Support\Fake\ImmediateTransactionManager(),
            $this->clock,
            new class implements \Daems\Domain\Shared\IdGeneratorInterface {
                public function generate(): string { return '01958000-0000-7000-8000-00000000aaaa'; }
            },
        );
    }

    private function acting(bool $platformAdmin, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: $platformAdmin, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    public function testTenantAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new ChangeMemberStatus($repo, $this->makeAnonymiseStub(), $this->clock))->execute(
            new ChangeMemberStatusInput(
                $this->acting(platformAdmin: false, role: UserTenantRole::Admin),
                UserId::generate()->value(), 'suspended', 'reason',
            ),
        );
    }

    public function testInvalidStatusThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new ChangeMemberStatus($repo, $this->makeAnonymiseStub(), $this->clock))->execute(
            new ChangeMemberStatusInput($this->acting(platformAdmin: true), UserId::generate()->value(), 'frobnicated', 'reason'),
        );
    }

    public function testEmptyReasonThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        (new ChangeMemberStatus($repo, $this->makeAnonymiseStub(), $this->clock))->execute(
            new ChangeMemberStatusInput($this->acting(platformAdmin: true), UserId::generate()->value(), 'suspended', '   '),
        );
    }

    public function testGsaSucceedsAndCallsRepo(): void
    {
        $repo = $this->createMock(MemberDirectoryRepositoryInterface::class);
        $repo->expects($this->once())->method('changeStatus');

        $out = (new ChangeMemberStatus($repo, $this->makeAnonymiseStub(), $this->clock))->execute(
            new ChangeMemberStatusInput($this->acting(platformAdmin: true), UserId::generate()->value(), 'suspended', 'overdue fees'),
        );

        self::assertTrue($out->success);
    }
}
