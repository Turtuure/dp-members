<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use DaemsModule\Members\Tests\Support\InMemoryTenantSupporterCounterRepository;
use Daems\Domain\Membership\MembershipType;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use PHPUnit\Framework\TestCase;

final class SupporterActivationServiceTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000010';
    private const USER_ID   = '01958000-0000-7000-8000-000000000011';

    public function test_activates_supporter_with_allocated_number(): void
    {
        $users    = new InMemoryUserRepository();
        $userTen  = new InMemoryUserTenantRepository();
        $counters = new InMemoryTenantSupporterCounterRepository();
        $clock    = new class implements \Daems\Domain\Shared\Clock {
            public function now(): \DateTimeImmutable
            { return new \DateTimeImmutable('2026-04-20 12:00:00'); }
        };
        $ids      = new class (self::USER_ID) implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function __construct(private readonly string $id) {}
            public function generate(): string { return $this->id; }
        };

        $counters->setNextForTesting(self::TENANT_ID, 17);

        $sut = new SupporterActivationService($users, $userTen, $counters, $clock, $ids);
        $out = $sut->execute(
            tenantId: self::TENANT_ID,
            applicationFields: [
                'name'    => 'Jane Doe',
                'email'   => 'jane@corp.test',
                'country' => 'FI',
            ],
        );

        self::assertSame(self::USER_ID, $out['userId']);
        self::assertSame('00017', $out['supporterNumber']);

        $user = $users->findByEmail('jane@corp.test');
        self::assertNotNull($user);
        self::assertSame('00017', $user->memberNumber());
        self::assertNull($user->dateOfBirth());
        self::assertSame(MembershipType::Supporting->value, $user->membershipType());
        self::assertTrue($userTen->hasRole(self::USER_ID, self::TENANT_ID, 'supporter'));
    }
}
