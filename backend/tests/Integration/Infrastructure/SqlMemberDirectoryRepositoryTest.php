<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Integration\Infrastructure;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DaemsModule\Members\Infrastructure\SqlMemberDirectoryRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Integration\MigrationTestCase;
use DateTimeImmutable;

final class SqlMemberDirectoryRepositoryTest extends MigrationTestCase
{
    private SqlMemberDirectoryRepository $repo;
    private TenantId $daemsTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(35);

        $this->repo = new SqlMemberDirectoryRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));

        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->daemsTenant = TenantId::fromString($daemsId);
    }

    private function seedMember(string $id, string $name, string $email, string $status = 'active', string $type = 'individual', ?string $memberNumber = null): UserId
    {
        $mn = $memberNumber !== null ? "'{$memberNumber}'" : 'NULL';
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth, membership_type, membership_status, member_number)
             VALUES ('{$id}', '{$name}', '{$email}', 'x', '1990-01-01', '{$type}', '{$status}', {$mn})"
        );
        $this->pdo()->exec(
            "INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
             VALUES ('{$id}', '" . $this->daemsTenant->value() . "', 'member', NOW())"
        );
        return UserId::fromString($id);
    }

    public function test_listMembersForTenant_returns_tenant_scoped_entries(): void
    {
        $this->seedMember('01958000-0000-7000-8000-aaa000000001', 'Alice', 'alice@x.com');
        $this->seedMember('01958000-0000-7000-8000-aaa000000002', 'Bob',   'bob@x.com');

        $result = $this->repo->listMembersForTenant($this->daemsTenant, [], 'name', 'ASC', 1, 50);

        self::assertCount(2, $result['entries']);
        self::assertSame(2, $result['total']);
        self::assertSame('Alice', $result['entries'][0]->name);
    }

    public function test_listMembersForTenant_filters_by_status(): void
    {
        $this->seedMember('01958000-0000-7000-8000-bbb000000001', 'Active',    'a@x.com', 'active');
        $this->seedMember('01958000-0000-7000-8000-bbb000000002', 'Suspended', 's@x.com', 'suspended');

        $result = $this->repo->listMembersForTenant($this->daemsTenant, ['status' => 'suspended'], 'name', 'ASC', 1, 50);

        self::assertCount(1, $result['entries']);
        self::assertSame('Suspended', $result['entries'][0]->name);
    }

    public function test_listMembersForTenant_search_q_matches_name_email_number(): void
    {
        $this->seedMember('01958000-0000-7000-8000-ccc000000001', 'Find Me',   'other@x.com', 'active', 'individual', '1001');
        $this->seedMember('01958000-0000-7000-8000-ccc000000002', 'OtherGuy',  'findme@x.com', 'active');
        $this->seedMember('01958000-0000-7000-8000-ccc000000003', 'ThirdUser', 'third@x.com', 'active', 'individual', '2-findme');

        $result = $this->repo->listMembersForTenant($this->daemsTenant, ['q' => 'findme'], 'name', 'ASC', 1, 50);

        self::assertCount(2, $result['entries']);
    }

    public function test_changeStatus_transactionally_updates_user_and_writes_audit(): void
    {
        $userId  = $this->seedMember('01958000-0000-7000-8000-ddd000000001', 'Target', 't@x.com', 'active');
        $adminId = $this->seedMember('01958000-0000-7000-8000-ddd000000002', 'Admin', 'admin@x.com', 'active');

        $this->repo->changeStatus(
            $userId, $this->daemsTenant, 'suspended', 'Late payment', $adminId, new DateTimeImmutable('2026-04-20 12:00:00'),
        );

        $userStatus = (string) $this->pdo()->query("SELECT membership_status FROM users WHERE id = '" . $userId->value() . "'")->fetchColumn();
        self::assertSame('suspended', $userStatus);

        $auditRows = $this->pdo()->query("SELECT previous_status, new_status, reason FROM member_status_audit WHERE user_id = '" . $userId->value() . "'")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $auditRows);
        self::assertSame('active', $auditRows[0]['previous_status']);
        self::assertSame('suspended', $auditRows[0]['new_status']);
        self::assertSame('Late payment', $auditRows[0]['reason']);
    }

    public function test_getAuditEntriesForMember_returns_reverse_chronological(): void
    {
        $userId  = $this->seedMember('01958000-0000-7000-8000-eee000000001', 'A', 'a@x.com', 'active');
        $adminId = $this->seedMember('01958000-0000-7000-8000-eee000000002', 'Admin', 'admin@x.com', 'active');

        $this->repo->changeStatus($userId, $this->daemsTenant, 'inactive', 'reason1', $adminId, new DateTimeImmutable('2026-01-01 10:00:00'));
        $this->repo->changeStatus($userId, $this->daemsTenant, 'active',   'reason2', $adminId, new DateTimeImmutable('2026-02-01 10:00:00'));

        $entries = $this->repo->getAuditEntriesForMember($userId, $this->daemsTenant, 10);

        self::assertCount(2, $entries);
        self::assertSame('reason2', $entries[0]->reason);
        self::assertSame('reason1', $entries[1]->reason);
    }
}
