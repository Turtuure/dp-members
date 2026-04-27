<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Isolation;

use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStatsInput;
use Daems\Domain\Shared\ValueObject\Uuid7;
use DaemsModule\Members\Infrastructure\SqlMemberApplicationRepository;
use DaemsModule\Members\Infrastructure\SqlSupporterApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Isolation\IsolationTestCase;

final class ApplicationsStatsTenantIsolationTest extends IsolationTestCase
{
    private ListApplicationsStats $usecase;

    protected function setUp(): void
    {
        parent::setUp();

        $conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        $this->usecase = new ListApplicationsStats(
            new SqlMemberApplicationRepository($conn),
            new SqlSupporterApplicationRepository($conn),
        );
    }

    private function seedMemberApp(string $tenantSlug, string $email, string $status, ?string $createdAt = null, ?string $decidedAt = null): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO member_applications
                (id, tenant_id, name, email, date_of_birth, country, motivation, how_heard, status, created_at, decided_at)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, ?, '1990-01-01', 'FI', 'm', NULL, ?, COALESCE(?, NOW()), ?)"
        );
        $stmt->execute([
            Uuid7::generate()->value(),
            $tenantSlug,
            'M-' . $email,
            $email,
            $status,
            $createdAt,
            $decidedAt,
        ]);
    }

    private function seedSupporterApp(string $tenantSlug, string $email, string $status, ?string $decidedAt = null): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO supporter_applications
                (id, tenant_id, org_name, contact_person, reg_no, email, country, motivation, how_heard, status, decided_at)
             VALUES (?, (SELECT id FROM tenants WHERE slug = ?), ?, 'Contact', NULL, ?, 'FI', 'm', NULL, ?, ?)"
        );
        $stmt->execute([
            Uuid7::generate()->value(),
            $tenantSlug,
            'Org-' . $email,
            $email,
            $status,
            $decidedAt,
        ]);
    }

    public function test_application_stats_isolated_per_tenant_with_asymmetric_seeds(): void
    {
        // Asymmetric seeds â€” a leaky impl (e.g. ignoring tenant_id) cannot pass.
        //
        // daems:
        //   member apps:    2 pending, 1 approved (created 3h ago, decided 1h ago â†’ 2h response)
        //   supporter apps: 1 pending
        //   â†’ pending = 3, approved_30d = 1, rejected_30d = 0, avg_response_hours = 2
        //
        // sahegroup:
        //   member apps:    1 pending
        //   supporter apps: 0
        //   â†’ pending = 1, approved_30d = 0, rejected_30d = 0
        $threeHoursAgo = (new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s');
        $oneHourAgo    = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');

        $this->seedMemberApp('daems',     'd-m1@example.test', 'pending');
        $this->seedMemberApp('daems',     'd-m2@example.test', 'pending');
        $this->seedMemberApp('daems',     'd-m3@example.test', 'approved', createdAt: $threeHoursAgo, decidedAt: $oneHourAgo);
        $this->seedSupporterApp('daems',  'd-s1@example.test', 'pending');

        $this->seedMemberApp('sahegroup', 's-m1@example.test', 'pending');

        $daemsAdmin = $this->makeActingUser(
            'daems',
            \Daems\Domain\Tenant\UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000d0001',
            email:  'admin-daems@test',
        );
        $saheAdmin = $this->makeActingUser(
            'sahegroup',
            \Daems\Domain\Tenant\UserTenantRole::Admin,
            userId: '01958000-0000-7000-8000-0000000a0001',
            email:  'admin-sahe@test',
        );

        $daems = $this->usecase->execute(
            new ListApplicationsStatsInput(acting: $daemsAdmin, tenantId: $this->tenantId('daems')),
        )->stats;
        $sahe = $this->usecase->execute(
            new ListApplicationsStatsInput(acting: $saheAdmin, tenantId: $this->tenantId('sahegroup')),
        )->stats;

        // daems: 2 member pending + 1 supporter pending = 3, 1 approved member, 0 rejected.
        // avg_response_hours: created 3h ago, decided 1h ago â†’ 2 hours.
        self::assertSame(3, $daems['pending']['value']);
        self::assertSame(1, $daems['approved_30d']['value']);
        self::assertSame(0, $daems['rejected_30d']['value']);
        self::assertSame(2, $daems['avg_response_hours']['value']);

        // sahegroup: 1 member pending only â€” sahegroup must NOT see daems' rows.
        self::assertSame(1, $sahe['pending']['value']);
        self::assertSame(0, $sahe['approved_30d']['value']);
        self::assertSame(0, $sahe['rejected_30d']['value']);
        self::assertSame(0, $sahe['avg_response_hours']['value']); // no decisions
    }
}
