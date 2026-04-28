<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplicationInput;
use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Config\BaseUrlResolverInterface;
use Daems\Domain\Invite\TokenGeneratorInterface;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
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

final class DecideApplicationTest extends TestCase
{
    private TenantId $tenant;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
        $this->clock = new class implements Clock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-04-20 12:00:00'); }
        };
    }

    private function acting(?UserTenantRole $role): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(), email: 't@x.com',
            isPlatformAdmin: false, activeTenant: $this->tenant,
            roleInActiveTenant: $role,
        );
    }

    private function buildSut(
        InMemoryMemberApplicationRepository $memberApps,
        InMemorySupporterApplicationRepository $supporterApps,
    ): DecideApplication {
        $users      = new InMemoryUserRepository();
        $userTenants = new InMemoryUserTenantRepository();
        $counters   = new InMemoryTenantMemberCounterRepository();
        $audit      = new InMemoryMemberStatusAuditRepository();
        $inviteRepo = new InMemoryUserInviteRepository();
        $dismissals = new InMemoryAdminApplicationDismissalRepository();

        $ids = new class implements \Daems\Domain\Shared\IdGeneratorInterface {
            private int $i = 0;
            private array $pool = [
                '01958000-0000-7000-8000-000000000090',
                '01958000-0000-7000-8000-000000000091',
                '01958000-0000-7000-8000-000000000092',
            ];
            public function generate(): string
            {
                return $this->pool[$this->i++ % count($this->pool)];
            }
        };

        $memberActivation = new MemberActivationService(
            $users, $userTenants, $counters, $audit, $this->clock, $ids,
        );
        $supporterActivation = new SupporterActivationService(
            $users, $userTenants, new \DaemsModule\Members\Tests\Support\InMemoryTenantSupporterCounterRepository(), $this->clock, $ids,
        );

        $tokenGen = new class implements TokenGeneratorInterface {
            public function generate(): string { return 'tok'; }
        };
        $urls = new class implements BaseUrlResolverInterface {
            public function resolveFrontendBaseUrl(string $tenantId): string { return 'https://x.test'; }
        };
        $issueInvite = new IssueInvite($inviteRepo, $tokenGen, $urls, $this->clock, $ids);

        return new DecideApplication(
            $memberApps,
            $supporterApps,
            $memberActivation,
            $supporterActivation,
            $issueInvite,
            $dismissals,
            new ImmediateTransactionManager(),
            $this->clock,
        );
    }

    public function testNonAdminForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        $sut = $this->buildSut(
            new InMemoryMemberApplicationRepository(),
            new InMemorySupporterApplicationRepository(),
        );

        $sut->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Member), 'member', 'id', 'approved', null),
        );
    }

    public function testInvalidDecisionThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);

        $sut = $this->buildSut(
            new InMemoryMemberApplicationRepository(),
            new InMemorySupporterApplicationRepository(),
        );

        $sut->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', 'id', 'maybe', null),
        );
    }

    public function testApplicationNotFoundThrows404(): void
    {
        $this->expectException(NotFoundException::class);

        $memberApps = new InMemoryMemberApplicationRepository();
        // No app seeded â€” findByIdForTenant returns null

        $sut = $this->buildSut($memberApps, new InMemorySupporterApplicationRepository());

        $sut->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', 'id', 'approved', null),
        );
    }

    public function testRecordsDecisionForMember(): void
    {
        $memberApps = new InMemoryMemberApplicationRepository();
        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->tenant, 'A', 'a@x.test', '1990-01-01', null, 'm', null, 'pending',
        );
        $memberApps->save($app);

        $sut = $this->buildSut($memberApps, new InMemorySupporterApplicationRepository());

        $out = $sut->execute(
            new DecideApplicationInput($this->acting(UserTenantRole::Admin), 'member', $app->id()->value(), 'approved', 'welcome'),
        );

        self::assertTrue($out->success);
    }
}
