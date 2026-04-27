<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Isolation;

use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationId;
use DaemsModule\Members\Infrastructure\SqlSupporterApplicationRepository;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Tests\Isolation\IsolationTestCase;

final class SupporterApplicationTenantIsolationTest extends IsolationTestCase
{
    private SqlSupporterApplicationRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlSupporterApplicationRepository(new Connection([
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
        $id = SupporterApplicationId::generate();

        $this->repo->save(new SupporterApplication(
            $id,
            $daemsTenant,
            'Acme Org',
            'Contact',
            null,
            'org@example.com',
            'FI',
            'motivation',
            null,
            'pending',
        ));

        $stmt = $this->pdo()->prepare('SELECT tenant_id FROM supporter_applications WHERE id = ?');
        $stmt->execute([$id->value()]);
        $row = $stmt->fetch();

        self::assertIsArray($row);
        self::assertSame($daemsTenant->value(), $row['tenant_id']);
    }
}
