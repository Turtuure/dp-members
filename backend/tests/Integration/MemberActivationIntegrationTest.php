<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Integration;

use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplicationInput;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Invite\InviteToken;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
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
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Infrastructure\Token\RandomTokenGenerator;
use Daems\Tests\Integration\MigrationTestCase;

final class MemberActivationIntegrationTest extends MigrationTestCase
{
    private Connection $conn;
    private string $tenantId;
    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(40);

        $this->conn = new Connection([
            'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port'     => getenv('TEST_DB_PORT') ?: '3306',
            'database' => getenv('TEST_DB_NAME') ?: 'daems_db_test',
            'username' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') ?: 'salasana',
        ]);

        // Seed tenant
        $this->tenantId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->tenantId, 'daems-int', 'Daems Integration Test']);

        // Seed admin user
        $this->adminId = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, is_platform_admin)
             VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([$this->adminId, 'Admin User', 'admin-int@test.com', password_hash('pass1234', PASSWORD_BCRYPT), '1980-01-01']);

        // Attach admin to tenant
        $this->pdo()->prepare(
            'INSERT INTO user_tenants (user_id, tenant_id, role, joined_at) VALUES (?, ?, ?, NOW())'
        )->execute([$this->adminId, $this->tenantId, 'admin']);
    }

    private function buildDecideApplication(): DecideApplication
    {
        $pdo = $this->conn->pdo();

        $userRepo      = new SqlUserRepository($this->conn);
        $userTenantRepo = new SqlUserTenantRepository($pdo);
        $counterRepo   = new SqlTenantMemberCounterRepository($pdo);
        $auditRepo     = new SqlMemberStatusAuditRepository($this->conn);
        $inviteRepo    = new SqlUserInviteRepository($pdo);
        $dismissRepo   = new SqlAdminApplicationDismissalRepository($pdo);
        $memberAppRepo = new SqlMemberApplicationRepository($this->conn);
        $supporterAppRepo = new SqlSupporterApplicationRepository($this->conn);
        $clock         = new SystemClock();
        $slugResolver  = new SqlTenantSlugResolver($pdo);
        $urlResolver   = new EnvBaseUrlResolver(
            ['daems-int' => 'http://daems-int.local'],
            'http://daems-platform.local',
            $slugResolver,
        );

        $idGen = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function generate(): string
            {
                return Uuid7::generate()->value();
            }
        };

        $memberActivation = new MemberActivationService(
            $userRepo, $userTenantRepo, $counterRepo, $auditRepo, $clock, $idGen,
        );
        $supporterActivation = new SupporterActivationService(
            $userRepo,
            $userTenantRepo,
            new \DaemsModule\Members\Infrastructure\SqlTenantSupporterCounterRepository($pdo),
            $clock,
            $idGen,
        );
        $issueInvite = new IssueInvite(
            $inviteRepo, new RandomTokenGenerator(), $urlResolver, $clock, $idGen,
        );
        $tx = new PdoTransactionManager($pdo);

        return new DecideApplication(
            $memberAppRepo,
            $supporterAppRepo,
            $memberActivation,
            $supporterActivation,
            $issueInvite,
            $dismissRepo,
            $tx,
            $clock,
        );
    }

    private function actingAdmin(): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString($this->adminId),
            email:              'admin-int@test.com',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString($this->tenantId),
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function seedPendingApp(string $name, string $email): MemberApplicationId
    {
        $id = MemberApplicationId::generate();
        $this->pdo()->prepare(
            "INSERT INTO member_applications (id, tenant_id, name, email, date_of_birth, motivation, status)
             VALUES (?, ?, ?, ?, '1995-05-05', 'motive', 'pending')"
        )->execute([$id->value(), $this->tenantId, $name, $email]);
        return $id;
    }

    public function test_full_approve_member_flow(): void
    {
        $appId = $this->seedPendingApp('Alice Tester', 'alice-int@test.com');
        $decide = $this->buildDecideApplication();

        $out = $decide->execute(new DecideApplicationInput(
            acting:   $this->actingAdmin(),
            type:     'member',
            id:       $appId->value(),
            decision: 'approved',
            note:     null,
        ));

        self::assertTrue($out->success);
        self::assertNotNull($out->activatedUserId);
        self::assertNotNull($out->memberNumber);
        self::assertSame('00001', $out->memberNumber);
        self::assertNotNull($out->inviteUrl);
        self::assertNotNull($out->inviteExpiresAt);

        $userId = $out->activatedUserId;

        // users row: member_number set, password_hash NULL
        $userRow = $this->pdo()->prepare('SELECT * FROM users WHERE id = ?')
            ->execute([$userId]) ? null : null;
        $stmt = $this->pdo()->prepare('SELECT member_number, password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('00001', $row['member_number']);
        self::assertNull($row['password_hash']);

        // user_tenants row: role=member, joined_at set
        $stmt2 = $this->pdo()->prepare(
            'SELECT role, joined_at FROM user_tenants WHERE user_id = ? AND tenant_id = ?'
        );
        $stmt2->execute([$userId, $this->tenantId]);
        $utRow = $stmt2->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($utRow);
        self::assertSame('member', $utRow['role']);
        self::assertNotNull($utRow['joined_at']);

        // member_status_audit row
        $stmt3 = $this->pdo()->prepare(
            'SELECT reason FROM member_status_audit WHERE user_id = ? AND tenant_id = ?'
        );
        $stmt3->execute([$userId, $this->tenantId]);
        $auditRow = $stmt3->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($auditRow);
        self::assertSame('application_approved', $auditRow['reason']);

        // tenant_member_counters next_value = 2
        $stmt4 = $this->pdo()->prepare(
            'SELECT next_value FROM tenant_member_counters WHERE tenant_id = ?'
        );
        $stmt4->execute([$this->tenantId]);
        $counterVal = $stmt4->fetchColumn();
        self::assertSame(2, (int) $counterVal);

        // user_invites row: used_at IS NULL
        $stmt5 = $this->pdo()->prepare(
            'SELECT used_at, expires_at FROM user_invites WHERE user_id = ?'
        );
        $stmt5->execute([$userId]);
        $inviteRow = $stmt5->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($inviteRow);
        self::assertNull($inviteRow['used_at']);

        // Expires roughly 7 days from now (within a 1-minute margin)
        $exp = new \DateTimeImmutable($inviteRow['expires_at']);
        $diffDays = (float) (($exp->getTimestamp() - time()) / 86400);
        self::assertGreaterThan(6.99, $diffDays);

        // member_applications.status = approved
        $stmt6 = $this->pdo()->prepare('SELECT status FROM member_applications WHERE id = ?');
        $stmt6->execute([$appId->value()]);
        self::assertSame('approved', $stmt6->fetchColumn());
    }

    public function test_sequential_approval_allocates_incrementing_member_numbers(): void
    {
        $appId1 = $this->seedPendingApp('Bob One', 'bob1-int@test.com');
        $appId2 = $this->seedPendingApp('Bob Two', 'bob2-int@test.com');
        $decide = $this->buildDecideApplication();

        $out1 = $decide->execute(new DecideApplicationInput($this->actingAdmin(), 'member', $appId1->value(), 'approved', null));
        $out2 = $decide->execute(new DecideApplicationInput($this->actingAdmin(), 'member', $appId2->value(), 'approved', null));

        self::assertSame('00001', $out1->memberNumber);
        self::assertSame('00002', $out2->memberNumber);
    }
}
