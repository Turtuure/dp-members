<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Integration\Infrastructure;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Members\Infrastructure\SqlMemberApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlMemberApplicationRepositoryTest extends MigrationTestCase
{
    private SqlMemberApplicationRepository $repo;
    private TenantId $daemsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(35);

        $this->repo = new SqlMemberApplicationRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));

        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->daemsTenant = TenantId::fromString($daemsId);
    }

    public function test_listPendingForTenant_returns_only_pending_same_tenant(): void
    {
        $this->repo->save(new MemberApplication(
            MemberApplicationId::generate(), $this->daemsTenant, 'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        ));
        $this->repo->save(new MemberApplication(
            MemberApplicationId::generate(), $this->daemsTenant, 'Bob', 'b@x.com', '1990-01-01', null, 'motive', null, 'approved',
        ));

        $results = $this->repo->listPendingForTenant($this->daemsTenant, 100);

        self::assertCount(1, $results);
        self::assertSame('Alice', $results[0]->name());
    }

    public function test_findByIdForTenant_returns_null_for_wrong_tenant(): void
    {
        $id = MemberApplicationId::generate();
        $this->repo->save(new MemberApplication(
            $id, $this->daemsTenant, 'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        ));

        $saheId = TenantId::fromString((string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='sahegroup'")->fetchColumn());

        self::assertNotNull($this->repo->findByIdForTenant($id->value(), $this->daemsTenant));
        self::assertNull($this->repo->findByIdForTenant($id->value(), $saheId));
    }

    public function test_recordDecision_updates_columns_and_writes_audit(): void
    {
        $decider = $this->seedAdminUser();

        $id = MemberApplicationId::generate();
        $this->repo->save(new MemberApplication(
            $id, $this->daemsTenant, 'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        ));

        $this->repo->recordDecision(
            $id->value(),
            $this->daemsTenant,
            'approved',
            $decider,
            'Welcome.',
            new DateTimeImmutable('2026-04-20 10:00:00'),
        );

        $row = $this->pdo()->query("SELECT status, decided_at, decided_by, decision_note FROM member_applications WHERE id = '" . $id->value() . "'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('approved', $row['status']);
        self::assertSame('2026-04-20 10:00:00', $row['decided_at']);
        self::assertSame($decider->value(), $row['decided_by']);
        self::assertSame('Welcome.', $row['decision_note']);

        $auditCount = (int) $this->pdo()->query("SELECT COUNT(*) FROM member_register_audit WHERE application_id = '" . $id->value() . "'")->fetchColumn();
        self::assertSame(1, $auditCount);
    }

    private function seedAdminUser(): UserId
    {
        $id = '01958000-0000-7000-8000-dec1de000001';
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES ('{$id}', 'Decider', 'decider@test', 'x', '1980-01-01', 1)"
        );
        return UserId::fromString($id);
    }
}
