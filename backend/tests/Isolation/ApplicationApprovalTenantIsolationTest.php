<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Isolation;

use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\PdoTransactionManager;
use DaemsModule\Members\Infrastructure\SqlAdminApplicationDismissalRepository;
use DaemsModule\Members\Infrastructure\SqlMemberApplicationRepository;
use DaemsModule\Members\Infrastructure\SqlMemberStatusAuditRepository;
use DaemsModule\Members\Infrastructure\SqlSupporterApplicationRepository;
use DaemsModule\Members\Infrastructure\SqlTenantMemberCounterRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantSlugResolver;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserInviteRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlUserTenantRepository;
use Daems\Infrastructure\Config\EnvBaseUrlResolver;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Infrastructure\Token\RandomTokenGenerator;
use Daems\Tests\Isolation\IsolationTestCase;

/**
 * Verifies that a daems admin cannot approve an application belonging to sahegroup.
 */
final class ApplicationApprovalTenantIsolationTest extends IsolationTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);
    }

    private function buildDecideApplication(): DecideApplication
    {
        $pdo = $this->conn->pdo();
        $clock = new SystemClock();
        $idGen = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string { return Uuid7::generate()->value(); }
        };

        return new DecideApplication(
            new SqlMemberApplicationRepository($this->conn),
            new SqlSupporterApplicationRepository($this->conn),
            new MemberActivationService(
                new SqlUserRepository($this->conn),
                new SqlUserTenantRepository($pdo),
                new SqlTenantMemberCounterRepository($pdo),
                new SqlMemberStatusAuditRepository($this->conn),
                $clock,
                $idGen,
            ),
            new SupporterActivationService(
                new SqlUserRepository($this->conn),
                new SqlUserTenantRepository($pdo),
                new \Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantSupporterCounterRepository($pdo),
                $clock,
                $idGen,
            ),
            new IssueInvite(
                new SqlUserInviteRepository($pdo),
                new RandomTokenGenerator(),
                new EnvBaseUrlResolver(
                    ['daems' => 'http://daem-society.local', 'sahegroup' => 'http://sahegroup.local'],
                    'http://daems-platform.local',
                    new SqlTenantSlugResolver($pdo),
                ),
                $clock,
                $idGen,
            ),
            new SqlAdminApplicationDismissalRepository($pdo),
            new PdoTransactionManager($pdo),
            $clock,
        );
    }

    public function test_daems_admin_cannot_approve_sahegroup_application(): void
    {
        // Seed an admin in the daems tenant
        $daemsAdmin = $this->makeActingUser('daems', UserTenantRole::Admin);

        // Seed a pending application in the sahegroup tenant
        $appId = Uuid7::generate()->value();
        $saheTenantId = $this->tenantId('sahegroup');
        $this->pdo()->prepare(
            "INSERT INTO member_applications (id, tenant_id, name, email, date_of_birth, motivation, status)
             VALUES (?, ?, 'Sahe Applicant', 'sahe@applicant.com', '1990-01-01', 'motive', 'pending')"
        )->execute([$appId, $saheTenantId->value()]);

        $decide = $this->buildDecideApplication();

        // The daems admin attempts to approve the sahegroup application.
        // DecideApplication scopes the lookup to the acting user's active tenant (daems),
        // so the sahegroup application should not be found.
        $this->expectException(NotFoundException::class);

        $decide->execute(new DecideApplicationInput(
            acting:   $daemsAdmin,
            type:     'member',
            id:       $appId,
            decision: 'approved',
            note:     null,
        ));
    }

    public function test_no_user_or_invite_created_after_cross_tenant_attempt(): void
    {
        $daemsAdmin = $this->makeActingUser('daems', UserTenantRole::Admin);

        $appId = Uuid7::generate()->value();
        $saheTenantId = $this->tenantId('sahegroup');
        $this->pdo()->prepare(
            "INSERT INTO member_applications (id, tenant_id, name, email, date_of_birth, motivation, status)
             VALUES (?, ?, 'Cross Attempt', 'cross@attempt.com', '1990-01-01', 'motive', 'pending')"
        )->execute([$appId, $saheTenantId->value()]);

        $decide = $this->buildDecideApplication();

        try {
            $decide->execute(new DecideApplicationInput($daemsAdmin, 'member', $appId, 'approved', null));
        } catch (NotFoundException) {
            // expected
        }

        // No users created with that email
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute(['cross@attempt.com']);
        self::assertSame(0, (int) $stmt->fetchColumn());

        // No user_invites created for sahe tenant
        $stmt2 = $this->pdo()->prepare('SELECT COUNT(*) FROM user_invites WHERE tenant_id = ?');
        $stmt2->execute([$saheTenantId->value()]);
        self::assertSame(0, (int) $stmt2->fetchColumn());
    }
}
