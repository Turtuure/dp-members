<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Tests\Support\InMemoryMemberStatusAuditRepository;
use DaemsModule\Members\Tests\Support\InMemoryTenantMemberCounterRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use PHPUnit\Framework\TestCase;

final class MemberActivationServiceTest extends TestCase
{
    // Valid UUID7 values for test fixtures
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000002';
    private const USER_ID   = '01958000-0000-7000-8000-000000000003';
    private const AUDIT_ID  = '01958000-0000-7000-8000-000000000004';

    public function test_activates_member_with_allocated_number_and_audit(): void
    {
        $users    = new InMemoryUserRepository();
        $userTen  = new InMemoryUserTenantRepository();
        $counter  = new InMemoryTenantMemberCounterRepository();
        $audit    = new InMemoryMemberStatusAuditRepository();
        $clock    = new class implements \Daems\Domain\Shared\Clock {
            public function now(): \DateTimeImmutable
            { return new \DateTimeImmutable('2026-04-20 12:00:00'); }
        };
        $i        = 0;
        $ids      = new class (self::USER_ID, self::AUDIT_ID) implements \Daems\Domain\Shared\IdGeneratorInterface {
            private int $i = 0;
            public function __construct(
                private readonly string $id1,
                private readonly string $id2,
            ) {}
            public function generate(): string
            {
                $this->i++;
                return $this->i === 1 ? $this->id1 : $this->id2;
            }
        };

        $counter->setNextForTesting(self::TENANT_ID, 42);

        $sut = new MemberActivationService($users, $userTen, $counter, $audit, $clock, $ids);
        $out = $sut->execute(
            tenantId: self::TENANT_ID,
            performingAdminId: self::ADMIN_ID,
            applicationFields: [
                'name'          => 'Firstname Lastname',
                'email'         => 'new@member.test',
                'date_of_birth' => '1990-05-15',
                'country'       => 'FI',
            ],
        );

        self::assertSame('00042', $out['memberNumber']);
        $user = $users->findByEmail('new@member.test');
        self::assertNotNull($user);
        self::assertSame('00042', $user->memberNumber());
        self::assertNull($user->passwordHash());
        self::assertTrue($userTen->hasRole($user->id()->value(), self::TENANT_ID, 'member'));
        self::assertCount(1, $audit->allForTenant(self::TENANT_ID));
    }
}
