<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Config\BaseUrlResolverInterface;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Invite\TokenGeneratorInterface;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\ImmediateTransactionManager;
use DaemsModule\Members\Tests\Support\InMemoryAdminApplicationDismissalRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemoryMemberStatusAuditRepository;
use DaemsModule\Members\Tests\Support\InMemorySupporterApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemoryTenantMemberCounterRepository;
use Daems\Tests\Support\Fake\InMemoryUserInviteRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\Fake\InMemoryUserTenantRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DecideApplicationApproveMemberTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000002';
    private const APP_ID    = '01958000-0000-7000-8000-000000000010';

    private TenantId $tenant;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString(self::TENANT_ID);
        $this->clock  = new class implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-04-20 12:00:00');
            }
        };
    }

    private function acting(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString(self::ADMIN_ID),
            email: 'admin@daems.test',
            isPlatformAdmin: false,
            activeTenant: $this->tenant,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function buildSut(
        InMemoryMemberApplicationRepository $memberApps,
        InMemoryAdminApplicationDismissalRepository $dismissals,
        InMemoryUserRepository $users,
        InMemoryUserTenantRepository $userTenants,
        InMemoryTenantMemberCounterRepository $counters,
        InMemoryMemberStatusAuditRepository $audit,
        InMemoryUserInviteRepository $inviteRepo,
    ): DecideApplication {
        $ids = $this->makeSequentialIds();

        $memberActivation = new MemberActivationService(
            $users,
            $userTenants,
            $counters,
            $audit,
            $this->clock,
            $ids,
        );

        $supporterActivation = new SupporterActivationService(
            $users,
            $userTenants,
            new \Daems\Tests\Support\Fake\InMemoryTenantSupporterCounterRepository(),
            $this->clock,
            $ids,
        );

        $tokenGen = new class implements TokenGeneratorInterface {
            public function generate(): string { return 'test-raw-token'; }
        };
        $urls = new class implements BaseUrlResolverInterface {
            public function resolveFrontendBaseUrl(string $tenantId): string
            {
                return 'https://daems.test';
            }
        };
        $issueInvite = new IssueInvite($inviteRepo, $tokenGen, $urls, $this->clock, $ids);

        return new DecideApplication(
            $memberApps,
            new InMemorySupporterApplicationRepository(),
            $memberActivation,
            $supporterActivation,
            $issueInvite,
            $dismissals,
            new ImmediateTransactionManager(),
            $this->clock,
        );
    }

    /** Generates predictable UUIDs sequentially from a fixed pool. */
    private function makeSequentialIds(): IdGeneratorInterface
    {
        return new class implements IdGeneratorInterface {
            private int $counter = 0;
            private array $pool = [
                '01958000-0000-7000-8000-000000000020', // userId
                '01958000-0000-7000-8000-000000000021', // auditId
                '01958000-0000-7000-8000-000000000022', // inviteId
                '01958000-0000-7000-8000-000000000023',
            ];

            public function generate(): string
            {
                return $this->pool[$this->counter++ % count($this->pool)];
            }
        };
    }

    public function test_approve_member_activates_issues_invite_clears_dismissals(): void
    {
        $memberApps = new InMemoryMemberApplicationRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $users      = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $counters   = new InMemoryTenantMemberCounterRepository();
        $audit      = new InMemoryMemberStatusAuditRepository();
        $inviteRepo = new InMemoryUserInviteRepository();

        // Seed pending member application
        $app = new MemberApplication(
            MemberApplicationId::fromString(self::APP_ID),
            $this->tenant,
            'Jane Doe',
            'jane@example.com',
            '1990-03-15',
            'FI',
            'I want to join',
            null,
            'pending',
        );
        $memberApps->save($app);

        // Seed two dismissals for this app by different admins
        $admin2Id = '01958000-0000-7000-8000-000000000099';
        $dismissals->save(new AdminApplicationDismissal(
            '01958000-0000-7000-8000-000000000030',
            self::ADMIN_ID,
            self::APP_ID,
            'member',
            new DateTimeImmutable('2026-04-19 10:00:00'),
        ));
        $dismissals->save(new AdminApplicationDismissal(
            '01958000-0000-7000-8000-000000000031',
            $admin2Id,
            self::APP_ID,
            'member',
            new DateTimeImmutable('2026-04-19 11:00:00'),
        ));

        $counters->setNextForTesting(self::TENANT_ID, 42);

        $sut = $this->buildSut($memberApps, $dismissals, $users, $userTenants, $counters, $audit, $inviteRepo);

        $out = $sut->execute(new DecideApplicationInput(
            acting: $this->acting(),
            type: 'member',
            id: self::APP_ID,
            decision: 'approved',
            note: 'Welcome!',
        ));

        // Output fields populated
        self::assertTrue($out->success);
        self::assertNotNull($out->activatedUserId);
        self::assertSame('00042', $out->memberNumber);
        self::assertNotNull($out->inviteUrl);
        self::assertStringContainsString('/invite/', $out->inviteUrl);
        self::assertNotNull($out->inviteExpiresAt);

        // User created
        $user = $users->findByEmail('jane@example.com');
        self::assertNotNull($user);
        self::assertSame('00042', $user->memberNumber());

        // user_tenants attached with 'member' role
        self::assertTrue($userTenants->hasRole($out->activatedUserId, self::TENANT_ID, 'member'));

        // member_status_audit row created
        self::assertCount(1, $audit->allForTenant(self::TENANT_ID));

        // user_invite row created
        $storedInvite = $inviteRepo->findByTokenHash(hash('sha256', 'test-raw-token'));
        self::assertNotNull($storedInvite);
        self::assertSame($out->activatedUserId, $storedInvite->userId);

        // Both dismissals deleted
        $remainingAdmin1 = $dismissals->listAppIdsDismissedByAdmin(self::ADMIN_ID);
        $remainingAdmin2 = $dismissals->listAppIdsDismissedByAdmin($admin2Id);
        self::assertEmpty($remainingAdmin1);
        self::assertEmpty($remainingAdmin2);

        // Application status updated to 'approved'
        $updated = $memberApps->findByIdForTenant(self::APP_ID, $this->tenant);
        self::assertNotNull($updated);
        self::assertSame('approved', $updated->status());
    }

    public function test_approve_member_is_idempotent_guard_on_already_decided(): void
    {
        $memberApps = new InMemoryMemberApplicationRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $users      = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $counters   = new InMemoryTenantMemberCounterRepository();
        $audit      = new InMemoryMemberStatusAuditRepository();
        $inviteRepo = new InMemoryUserInviteRepository();

        // Seed already-approved application
        $app = new MemberApplication(
            MemberApplicationId::fromString(self::APP_ID),
            $this->tenant,
            'Jane Doe',
            'jane@example.com',
            '1990-03-15',
            'FI',
            'I want to join',
            null,
            'approved',
        );
        $memberApps->save($app);

        $sut = $this->buildSut($memberApps, $dismissals, $users, $userTenants, $counters, $audit, $inviteRepo);

        $this->expectException(ValidationException::class);

        $sut->execute(new DecideApplicationInput(
            acting: $this->acting(),
            type: 'member',
            id: self::APP_ID,
            decision: 'approved',
            note: null,
        ));
    }

    public function test_reject_member_does_not_create_user_or_invite(): void
    {
        $memberApps = new InMemoryMemberApplicationRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();
        $users      = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $counters   = new InMemoryTenantMemberCounterRepository();
        $audit      = new InMemoryMemberStatusAuditRepository();
        $inviteRepo = new InMemoryUserInviteRepository();

        $app = new MemberApplication(
            MemberApplicationId::fromString(self::APP_ID),
            $this->tenant,
            'Jane Doe',
            'jane@example.com',
            '1990-03-15',
            'FI',
            'I want to join',
            null,
            'pending',
        );
        $memberApps->save($app);

        $sut = $this->buildSut($memberApps, $dismissals, $users, $userTenants, $counters, $audit, $inviteRepo);

        $out = $sut->execute(new DecideApplicationInput(
            acting: $this->acting(),
            type: 'member',
            id: self::APP_ID,
            decision: 'rejected',
            note: 'Not suitable',
        ));

        self::assertTrue($out->success);
        self::assertNull($out->activatedUserId);
        self::assertNull($out->memberNumber);
        self::assertNull($out->inviteUrl);

        // No user created
        self::assertEmpty($users->byId);

        // No invite created
        self::assertNull($inviteRepo->findByTokenHash(hash('sha256', 'test-raw-token')));

        // Application status is 'rejected'
        $updated = $memberApps->findByIdForTenant(self::APP_ID, $this->tenant);
        self::assertNotNull($updated);
        self::assertSame('rejected', $updated->status());
    }
}
