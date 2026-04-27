<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\GetApplicationDetail\GetApplicationDetail;
use DaemsModule\Members\Application\Backstage\GetApplicationDetail\GetApplicationDetailInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GetApplicationDetailTest extends TestCase
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

        (new GetApplicationDetail($memberRepo, $supporterRepo))->execute(
            new GetApplicationDetailInput($this->acting(UserTenantRole::Member), 'member', 'app-1'),
        );
    }

    public function testReturnsMemberApplicationDetail(): void
    {
        $row = [
            'id'            => 'mem-1',
            'name'          => 'Alice',
            'email'         => 'a@x.com',
            'date_of_birth' => '1990-01-01',
            'country'       => 'FI',
            'motivation'    => 'I want to join',
            'how_heard'     => 'friend',
            'status'        => 'pending',
            'created_at'    => '2026-04-01 10:00:00',
            'decided_at'    => null,
            'decision_note' => null,
        ];
        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->expects(self::once())
            ->method('findDetailedByIdForTenant')
            ->with('mem-1', $this->tenant)
            ->willReturn($row);

        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->expects(self::never())->method('findDetailedByIdForTenant');

        $out = (new GetApplicationDetail($memberRepo, $supporterRepo))->execute(
            new GetApplicationDetailInput($this->acting(UserTenantRole::Admin), 'member', 'mem-1'),
        );

        self::assertSame('member', $out->type);
        self::assertSame('Alice', $out->application['name']);
        self::assertSame('pending', $out->application['status']);

        $arr = $out->toArray();
        self::assertSame('member', $arr['type']);
        self::assertSame($row, $arr['application']);
    }

    public function testReturnsSupporterApplicationDetailWithDecisionMetadata(): void
    {
        $row = [
            'id'             => 'sup-1',
            'org_name'       => 'OrgX',
            'contact_person' => 'Bob',
            'reg_no'         => 'FI12345',
            'email'          => 'b@x.com',
            'country'        => 'FI',
            'motivation'     => 'sponsor',
            'how_heard'      => 'event',
            'status'         => 'rejected',
            'created_at'     => '2026-04-01 10:00:00',
            'decided_at'     => '2026-04-05 14:00:00',
            'decision_note'  => 'duplicate registration',
        ];
        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->expects(self::never())->method('findDetailedByIdForTenant');

        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);
        $supporterRepo->expects(self::once())
            ->method('findDetailedByIdForTenant')
            ->with('sup-1', $this->tenant)
            ->willReturn($row);

        $out = (new GetApplicationDetail($memberRepo, $supporterRepo))->execute(
            new GetApplicationDetailInput($this->acting(UserTenantRole::Admin), 'supporter', 'sup-1'),
        );

        self::assertSame('supporter', $out->type);
        self::assertSame('OrgX', $out->application['org_name']);
        self::assertSame('rejected', $out->application['status']);
        self::assertSame('duplicate registration', $out->application['decision_note']);
    }

    public function testThrowsNotFoundWhenMissing(): void
    {
        $this->expectException(NotFoundException::class);

        $memberRepo = $this->createMock(MemberApplicationRepositoryInterface::class);
        $memberRepo->method('findDetailedByIdForTenant')->willReturn(null);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new GetApplicationDetail($memberRepo, $supporterRepo))->execute(
            new GetApplicationDetailInput($this->acting(UserTenantRole::Admin), 'member', 'unknown'),
        );
    }

    public function testThrowsInvalidArgumentForUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $memberRepo    = $this->createMock(MemberApplicationRepositoryInterface::class);
        $supporterRepo = $this->createMock(SupporterApplicationRepositoryInterface::class);

        (new GetApplicationDetail($memberRepo, $supporterRepo))->execute(
            new GetApplicationDetailInput($this->acting(UserTenantRole::Admin), 'bogus', 'x'),
        );
    }
}
