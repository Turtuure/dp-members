<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Isolation;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use DaemsModule\Members\Infrastructure\SqlMemberApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Isolation\IsolationTestCase;

final class MemberApplicationTenantIsolationTest extends IsolationTestCase
{
    private SqlMemberApplicationRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlMemberApplicationRepository(new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]));
    }

    public function test_save_persists_tenant_id(): void
    {
        $daemsTenant = $this->tenantId('daems');
        $id = MemberApplicationId::generate();

        $this->repo->save(new MemberApplication(
            $id,
            $daemsTenant,
            'Alice',
            'alice@example.com',
            '1990-01-01',
            'FI',
            'motivation',
            null,
            'pending',
        ));

        $stmt = $this->pdo()->prepare('SELECT tenant_id FROM member_applications WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch();

        self::assertIsArray($row);
        self::assertSame($daemsTenant->value(), $row['tenant_id']);
    }

    public function test_separate_tenants_get_separate_rows(): void
    {
        $daemsId = MemberApplicationId::generate();
        $saheId = MemberApplicationId::generate();

        $this->repo->save(new MemberApplication($daemsId, $this->tenantId('daems'), 'D', 'd@x.com', '1990-01-01', null, 'm', null, 'pending'));
        $this->repo->save(new MemberApplication($saheId, $this->tenantId('sahegroup'), 'S', 's@x.com', '1990-01-01', null, 'm', null, 'pending'));

        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM member_applications WHERE tenant_id = (SELECT id FROM tenants WHERE slug = ?)'
        );
        $stmt->execute(['daems']);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $stmt->execute(['sahegroup']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
