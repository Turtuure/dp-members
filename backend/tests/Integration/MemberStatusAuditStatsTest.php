<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Members\Infrastructure\SqlMemberStatusAuditRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class MemberStatusAuditStatsTest extends MigrationTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
    }

    public function test_inactive_transitions_today_lands_on_last_sparkline_entry(): void
    {
        $tenantId = $this->tenantId('daems');
        $adminId  = $this->seedUser('admin@example.test');
        $userId   = $this->seedUser('alice@example.test');

        $this->pdo()->prepare(
            'INSERT INTO member_status_audit (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->generateUuidV7(),
            $tenantId->value(),
            $userId,
            'active',
            'inactive',
            'reason text',
            $adminId,
        ]);

        $repo   = new SqlMemberStatusAuditRepository($this->conn);
        $series = $repo->dailyTransitionsForTenant($tenantId, 'inactive');

        self::assertCount(30, $series);
        self::assertSame(['date', 'value'], array_keys($series[0]));

        $todayKey    = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $todayBucket = null;
        foreach ($series as $point) {
            if ($point['date'] === $todayKey) {
                $todayBucket = $point;
                break;
            }
        }
        self::assertNotNull($todayBucket, 'today must appear in series');
        self::assertSame(1, $todayBucket['value']);

        // Sparkline window: first entry = 29 days ago, last entry = today.
        $expectedFirst = (new \DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
        self::assertSame($expectedFirst, $series[0]['date']);
        self::assertSame($todayKey,      $series[29]['date']);
    }

    public function test_filters_by_new_status(): void
    {
        $tenantId = $this->tenantId('daems');
        $adminId  = $this->seedUser('admin@example.test');
        $userId   = $this->seedUser('bob@example.test');

        $stmt = $this->pdo()->prepare(
            'INSERT INTO member_status_audit (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$this->generateUuidV7(), $tenantId->value(), $userId, 'inactive', 'active',   'r', $adminId]);
        $stmt->execute([$this->generateUuidV7(), $tenantId->value(), $userId, 'active',   'inactive', 'r', $adminId]);

        $repo = new SqlMemberStatusAuditRepository($this->conn);

        $inactiveSeries = $repo->dailyTransitionsForTenant($tenantId, 'inactive');
        $activeSeries   = $repo->dailyTransitionsForTenant($tenantId, 'active');

        $todayKey = (new \DateTimeImmutable('today'))->format('Y-m-d');
        self::assertSame(1, $this->bucketValue($inactiveSeries, $todayKey));
        self::assertSame(1, $this->bucketValue($activeSeries, $todayKey));
    }

    public function test_isolated_per_tenant(): void
    {
        $daemsId   = $this->tenantId('daems');
        $sahegroup = $this->tenantId('sahegroup');
        $admin     = $this->seedUser('admin@example.test');
        $u         = $this->seedUser('cross@example.test');

        $this->pdo()->prepare(
            'INSERT INTO member_status_audit (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$this->generateUuidV7(), $sahegroup->value(), $u, 'active', 'inactive', 'r', $admin]);

        $repo   = new SqlMemberStatusAuditRepository($this->conn);
        $series = $repo->dailyTransitionsForTenant($daemsId, 'inactive');

        self::assertSame(0, array_sum(array_column($series, 'value')));
    }

    /**
     * @param list<array{date: string, value: int}> $series
     */
    private function bucketValue(array $series, string $date): int
    {
        foreach ($series as $point) {
            if ($point['date'] === $date) {
                return $point['value'];
            }
        }
        return 0;
    }

    private function tenantId(string $slug): TenantId
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM tenants WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            $this->fail("Tenant not seeded: $slug");
        }
        return TenantId::fromString((string) $row['id']);
    }

    private function seedUser(string $email): string
    {
        $id = $this->generateUuidV7();
        $this->pdo()->prepare(
            "INSERT INTO users
                (id, name, email, password_hash, date_of_birth,
                 country, address_street, address_zip, address_city, address_country,
                 membership_type, membership_status, member_number)
             VALUES (?, ?, ?, 'x', '1990-01-01',
                     '', '', '', '', '',
                     'individual', 'active', NULL)"
        )->execute([$id, $email, $email]);
        return $id;
    }

    private function generateUuidV7(): string
    {
        return Uuid7::generate()->value();
    }
}
