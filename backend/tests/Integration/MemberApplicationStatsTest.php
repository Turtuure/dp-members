<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Members\Infrastructure\SqlMemberApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;

final class MemberApplicationStatsTest extends MigrationTestCase
{
    private Connection $conn;
    private SqlMemberApplicationRepository $repo;

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

        $this->repo = new SqlMemberApplicationRepository($this->conn);
    }

    public function test_stats_for_tenant_returns_pending_decided_counts_and_avg_helpers(): void
    {
        $tenantId = $this->tenantId('daems');

        // 1 pending â€” created today
        $this->insertApp($tenantId, 'pending', createdHoursAgo: 1, decidedHoursAgo: null);
        // 1 approved â€” created 5h ago, decided now (~5h response)
        $this->insertApp($tenantId, 'approved', createdHoursAgo: 5, decidedHoursAgo: 0);
        // 1 rejected â€” created 2h ago, decided now (~2h response)
        $this->insertApp($tenantId, 'rejected', createdHoursAgo: 2, decidedHoursAgo: 0);

        $stats = $this->repo->statsForTenant($tenantId);

        self::assertSame(1, $stats['pending']['value']);
        self::assertSame(1, $stats['approved_30d']['value']);
        self::assertSame(1, $stats['rejected_30d']['value']);
        self::assertSame(2, $stats['decided_count']);
        self::assertGreaterThan(0, $stats['decided_total_hours']);

        self::assertCount(30, $stats['pending']['sparkline']);
        self::assertCount(30, $stats['approved_30d']['sparkline']);
        self::assertCount(30, $stats['rejected_30d']['sparkline']);

        self::assertSame(['date', 'value'], array_keys($stats['pending']['sparkline'][0]));

        // Sparkline window: first entry = 29 days ago, last entry = today.
        $expectedFirst = (new \DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
        $expectedLast  = (new \DateTimeImmutable('today'))->format('Y-m-d');
        self::assertSame($expectedFirst, $stats['pending']['sparkline'][0]['date']);
        self::assertSame($expectedLast,  $stats['pending']['sparkline'][29]['date']);

        // Today's pending bucket should have value 1 (the row was created today).
        self::assertSame(1, $stats['pending']['sparkline'][29]['value']);
        // Today's approved/rejected buckets should have value 1 each (decided today).
        self::assertSame(1, $stats['approved_30d']['sparkline'][29]['value']);
        self::assertSame(1, $stats['rejected_30d']['sparkline'][29]['value']);
    }

    public function test_stats_isolated_per_tenant(): void
    {
        $daems = $this->tenantId('daems');
        $sahe  = $this->tenantId('sahegroup');

        $this->insertApp($sahe, 'pending', createdHoursAgo: 1, decidedHoursAgo: null);
        $this->insertApp($sahe, 'approved', createdHoursAgo: 3, decidedHoursAgo: 0);

        $daemsStats = $this->repo->statsForTenant($daems);
        $saheStats  = $this->repo->statsForTenant($sahe);

        self::assertSame(0, $daemsStats['pending']['value']);
        self::assertSame(0, $daemsStats['approved_30d']['value']);
        self::assertSame(0, $daemsStats['rejected_30d']['value']);
        self::assertSame(0, $daemsStats['decided_count']);
        self::assertSame(0, $daemsStats['decided_total_hours']);

        self::assertSame(1, $saheStats['pending']['value']);
        self::assertSame(1, $saheStats['approved_30d']['value']);
    }

    public function test_decisions_outside_30d_window_excluded(): void
    {
        $tenantId = $this->tenantId('daems');

        // Decided 40 days ago â€” must NOT count toward decided_30d/avg helpers.
        $this->insertApp($tenantId, 'approved', createdHoursAgo: 24 * 41, decidedHoursAgo: 24 * 40);

        $stats = $this->repo->statsForTenant($tenantId);

        self::assertSame(0, $stats['approved_30d']['value']);
        self::assertSame(0, $stats['decided_count']);
        self::assertSame(0, $stats['decided_total_hours']);
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

    /**
     * Insert a member_applications row with controllable created_at / decided_at offsets.
     *
     * @param int      $createdHoursAgo  Hours before NOW to backdate created_at.
     * @param int|null $decidedHoursAgo  Hours before NOW for decided_at; null = still pending.
     */
    private function insertApp(
        TenantId $tenantId,
        string $status,
        int $createdHoursAgo,
        ?int $decidedHoursAgo,
    ): string {
        $id        = Uuid7::generate()->value();
        $createdAt = (new \DateTimeImmutable('now'))->modify("-{$createdHoursAgo} hours")->format('Y-m-d H:i:s');
        $decidedAt = $decidedHoursAgo === null
            ? null
            : (new \DateTimeImmutable('now'))->modify("-{$decidedHoursAgo} hours")->format('Y-m-d H:i:s');

        $this->pdo()->prepare(
            'INSERT INTO member_applications
                (id, tenant_id, name, email, date_of_birth, country, motivation, how_heard, status, created_at, decided_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $tenantId->value(),
            'Applicant ' . substr($id, 0, 8),
            'app+' . substr($id, 0, 8) . '@example.test',
            '1990-01-01',
            'FI',
            'motivation text',
            'word of mouth',
            $status,
            $createdAt,
            $decidedAt,
        ]);

        return $id;
    }
}
