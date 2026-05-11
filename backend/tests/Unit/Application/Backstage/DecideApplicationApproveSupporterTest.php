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
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationId;
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

final class DecideApplicationApproveSupporterTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-000000000002';
    private const APP_ID    = '01958000-0000-7000-8000-000000000050';

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

    private function makeSequentialIds(): IdGeneratorInterface
    {
        return new class implements IdGeneratorInterface {
            private int $counter = 0;
            private array $pool = [
                '01958000-0000-7000-8000-000000000060', // userId
                '01958000-0000-7000-8000-000000000061', // inviteId
                '01958000-0000-7000-8000-000000000062',
            ];

            public function generate(): string
            {
                return $this->pool[$this->counter++ % count($this->pool)];
            }
        };
    }

    private function buildSut(
        InMemorySupporterApplicationRepository $supporterApps,
        InMemoryAdminApplicationDismissalRepository $dismissals,
        InMemoryUserRepository $users,
        InMemoryUserTenantRepository $userTenants,
        InMemoryUserInviteRepository $inviteRepo,
    ): DecideApplication {
        $ids = $this->makeSequentialIds();

        $memberActivation = new MemberActivationService(
            $users,
            $userTenants,
            new InMemoryTenantMemberCounterRepository(),
            new InMemoryMemberStatusAuditRepository(),
            $this->clock,
            $ids,
        );

        $supporterActivation = new SupporterActivationService(
            $users,
            $userTenants,
            new \DaemsModule\Members\Tests\Support\InMemoryTenantSupporterCounterRepository(),
            $this->clock,
            $ids,
        );

        $tokenGen = new class implements TokenGeneratorInterface {
            public function generate(): string { return 'supporter-token'; }
        };
        $urls = new class implements BaseUrlResolverInterface {
            public function resolveFrontendBaseUrl(string $tenantId): string
            {
                return 'https://daems.test';
            }
        };
        $issueInvite = new IssueInvite($inviteRepo, $tokenGen, $urls, $this->clock, $ids);

        return new DecideApplication(
            new InMemoryMemberApplicationRepository(),
            $supporterApps,
            $memberActivation,
            $supporterActivation,
            $issueInvite,
            $dismissals,
            new ImmediateTransactionManager(),
            $this->clock,
        );
    }

    public function test_approve_supporter_activates_issues_invite_clears_dismissals(): void
    {
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $users         = new InMemoryUserRepository();
        $userTenants   = new InMemoryUserTenantRepository();
        $inviteRepo    = new InMemoryUserInviteRepository();

        // Seed pending supporter application
        $app = new SupporterApplication(
            SupporterApplicationId::fromString(self::APP_ID),
            $this->tenant,
            'Acme Corp',
            'Bob Smith',
            'FI1234567',
            'bob@acme.com',
            'FI',
            'We support your cause',
            null,
            'pending',
        );
        $supporterApps->save($app);

        // Seed two dismissals for this app
        $admin2Id = '01958000-0000-7000-8000-000000000099';
        $dismissals->save(new AdminApplicationDismissal(
            '01958000-0000-7000-8000-000000000070',
            self::ADMIN_ID,
            self::APP_ID,
            'supporter',
            new DateTimeImmutable('2026-04-19 10:00:00'),
        ));
        $dismissals->save(new AdminApplicationDismissal(
            '01958000-0000-7000-8000-000000000071',
            $admin2Id,
            self::APP_ID,
            'supporter',
            new DateTimeImmutable('2026-04-19 11:00:00'),
        ));

        $sut = $this->buildSut($supporterApps, $dismissals, $users, $userTenants, $inviteRepo);

        $out = $sut->execute(new DecideApplicationInput(
            acting: $this->acting(),
            type: 'supporter',
            id: self::APP_ID,
            decision: 'approved',
            note: 'Welcome supporter!',
        ));

        // Output fields populated (no memberNumber for supporters)
        self::assertTrue($out->success);
        self::assertNotNull($out->activatedUserId);
        self::assertNull($out->memberNumber);
        self::assertNotNull($out->inviteUrl);
        self::assertStringContainsString('/invite/', $out->inviteUrl);
        self::assertNotNull($out->inviteExpiresAt);

        // User created with supporter membership type
        $user = $users->findByEmail('bob@acme.com');
        self::assertNotNull($user);
        self::assertSame(MembershipType::Supporting->value, $user->membershipType());

        // user_tenants attached with 'supporter' role
        self::assertTrue($userTenants->hasRole($out->activatedUserId, self::TENANT_ID, 'supporter'));

        // Supporter applications have NO member_status_audit â€” no assertion needed here

        // user_invite row created
        $storedInvite = $inviteRepo->findByTokenHash(hash('sha256', 'supporter-token'));
        self::assertNotNull($storedInvite);
        self::assertSame($out->activatedUserId, $storedInvite->userId);

        // Both dismissals deleted
        self::assertEmpty($dismissals->listAppIdsDismissedByAdmin(self::ADMIN_ID));
        self::assertEmpty($dismissals->listAppIdsDismissedByAdmin($admin2Id));

        // Application status updated to 'approved'
        $updated = $supporterApps->findByIdForTenant(self::APP_ID, $this->tenant);
        self::assertNotNull($updated);
        self::assertSame('approved', $updated->status());
    }

    public function test_approve_supporter_is_idempotent_guard_on_already_decided(): void
    {
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $users         = new InMemoryUserRepository();
        $userTenants   = new InMemoryUserTenantRepository();
        $inviteRepo    = new InMemoryUserInviteRepository();

        // Already-rejected application
        $app = new SupporterApplication(
            SupporterApplicationId::fromString(self::APP_ID),
            $this->tenant,
            'Acme Corp',
            'Bob Smith',
            null,
            'bob@acme.com',
            'FI',
            'We support',
            null,
            'rejected',
        );
        $supporterApps->save($app);

        $sut = $this->buildSut($supporterApps, $dismissals, $users, $userTenants, $inviteRepo);

        $this->expectException(ValidationException::class);

        $sut->execute(new DecideApplicationInput(
            acting: $this->acting(),
            type: 'supporter',
            id: self::APP_ID,
            decision: 'approved',
            note: null,
        ));
    }

    public function test_reject_supporter_does_not_create_user_or_invite(): void
    {
        $supporterApps = new InMemorySupporterApplicationRepository();
        $dismissals    = new InMemoryAdminApplicationDismissalRepository();
        $users         = new InMemoryUserRepository();
        $userTenants   = new InMemoryUserTenantRepository();
        $inviteRepo    = new InMemoryUserInviteRepository();

        $app = new SupporterApplication(
            SupporterApplicationId::fromString(self::APP_ID),
            $this->tenant,
            'Acme Corp',
            'Bob Smith',
            null,
            'bob@acme.com',
            'FI',
            'We support',
            null,
            'pending',
        );
        $supporterApps->save($app);

        $sut = $this->buildSut($supporterApps, $dismissals, $users, $userTenants, $inviteRepo);

        $out = $sut->execute(new DecideApplicationInput(
            acting: $this->acting(),
            type: 'supporter',
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
        self::assertNull($inviteRepo->findByTokenHash(hash('sha256', 'supporter-token')));

        // Application status is 'rejected'
        $updated = $supporterApps->findByIdForTenant(self::APP_ID, $this->tenant);
        self::assertNotNull($updated);
        self::assertSame('rejected', $updated->status());
    }
}
