<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Isolation;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Tests\Isolation\IsolationTestCase;

final class MembersStatsTenantIsolationTest extends IsolationTestCase
{
    private SqlUserTenantRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlUserTenantRepository($this->pdo());
    }

    private function seedMember(string $tenantSlug, string $email, string $membershipType = 'individual', string $membershipStatus = 'active'): void
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            "INSERT INTO users
                (id, name, email, password_hash, date_of_birth,
                 country, address_street, address_zip, address_city, address_country,
                 membership_type, membership_status)
             VALUES (?, ?, ?, 'x', '1990-01-01',
                     '', '', '', '', '',
                     ?, ?)"
        )->execute([$id, 'M-' . $email, $email, $membershipType, $membershipStatus]);

        $this->repo->attach(UserId::fromString($id), $this->tenantId($tenantSlug), UserTenantRole::Member);
    }

    public function test_membership_stats_isolated_per_tenant_with_asymmetric_seeds(): void
    {
        // Asymmetric seeding so a leaky implementation (e.g. ignoring tenant_id) cannot pass:
        //   daems:     3 members, 1 supporter (membership_type), 1 inactive (membership_status)
        //   sahegroup: 2 members, 0 supporters,                  0 inactive
        $this->seedMember('daems',     'd1@example.test');
        $this->seedMember('daems',     'd2@example.test', membershipType: 'supporter');
        $this->seedMember('daems',     'd3@example.test', membershipStatus: 'inactive');
        $this->seedMember('sahegroup', 's1@example.test');
        $this->seedMember('sahegroup', 's2@example.test');

        $daems = $this->repo->membershipStatsForTenant($this->tenantId('daems'));
        $sahe  = $this->repo->membershipStatsForTenant($this->tenantId('sahegroup'));

        self::assertSame(3, $daems['total_members']['value']);
        self::assertSame(1, $daems['supporters']['value']);
        self::assertSame(1, $daems['inactive']['value']);

        self::assertSame(2, $sahe['total_members']['value']);
        self::assertSame(0, $sahe['supporters']['value']);
        self::assertSame(0, $sahe['inactive']['value']);
    }
}
