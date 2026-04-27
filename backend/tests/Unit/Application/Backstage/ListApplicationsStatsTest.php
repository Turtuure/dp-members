<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStatsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationId;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\ActingUserFactory;
use DaemsModule\Members\Tests\Support\InMemoryMemberApplicationRepository;
use DaemsModule\Members\Tests\Support\InMemorySupporterApplicationRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ListApplicationsStatsTest extends TestCase
{
    private const TENANT_ID = '019d0000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '019d0000-0000-7000-8000-000000000a01';
    private const USER_ID   = '019d0000-0000-7000-8000-000000000a02';

    // Member application ids
    private const M_APP_1 = '019d0000-0000-7000-8000-000000001001';
    private const M_APP_2 = '019d0000-0000-7000-8000-000000001002';
    private const M_APP_3 = '019d0000-0000-7000-8000-000000001003';
    private const M_APP_4 = '019d0000-0000-7000-8000-000000001004';
    private const M_APP_5 = '019d0000-0000-7000-8000-000000001005';
    private const M_APP_6 = '019d0000-0000-7000-8000-000000001006';

    // Supporter application ids
    private const S_APP_1 = '019d0000-0000-7000-8000-000000002001';

    public function test_combines_member_and_supporter_slices_with_summed_kpis(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $memberRepo    = new InMemoryMemberApplicationRepository();
        $supporterRepo = new InMemorySupporterApplicationRepository();

        // Seed 3 pending member apps.
        $memberRepo->save($this->makeMemberApp(self::M_APP_1, $tenantId, 'pending'));
        $memberRepo->save($this->makeMemberApp(self::M_APP_2, $tenantId, 'pending'));
        $memberRepo->save($this->makeMemberApp(self::M_APP_3, $tenantId, 'pending'));

        // Seed 2 approved member apps + record decisions.
        $memberRepo->save($this->makeMemberApp(self::M_APP_4, $tenantId, 'pending'));
        $memberRepo->save($this->makeMemberApp(self::M_APP_5, $tenantId, 'pending'));
        $memberRepo->recordDecision(
            self::M_APP_4,
            $tenantId,
            'approved',
            UserId::fromString(self::ADMIN_ID),
            null,
            new DateTimeImmutable('now'),
        );
        $memberRepo->recordDecision(
            self::M_APP_5,
            $tenantId,
            'approved',
            UserId::fromString(self::ADMIN_ID),
            null,
            new DateTimeImmutable('now'),
        );

        // Seed 1 rejected member app.
        $memberRepo->save($this->makeMemberApp(self::M_APP_6, $tenantId, 'pending'));
        $memberRepo->recordDecision(
            self::M_APP_6,
            $tenantId,
            'rejected',
            UserId::fromString(self::ADMIN_ID),
            null,
            new DateTimeImmutable('now'),
        );

        // Seed 1 pending supporter app.
        $supporterRepo->save($this->makeSupporterApp(self::S_APP_1, $tenantId, 'pending'));

        $usecase = new ListApplicationsStats($memberRepo, $supporterRepo);
        $out     = $usecase->execute(new ListApplicationsStatsInput(acting: $admin, tenantId: $tenantId));

        // Summed KPI values: member 3 pending + supporter 1 pending = 4.
        self::assertSame(4, $out->stats['pending']['value']);
        self::assertSame(2, $out->stats['approved_30d']['value']);
        self::assertSame(1, $out->stats['rejected_30d']['value']);

        // Element-wise summed sparklines: 30 entries, both repos contribute zero-filled days.
        self::assertCount(30, $out->stats['pending']['sparkline']);
        self::assertCount(30, $out->stats['approved_30d']['sparkline']);
        self::assertCount(30, $out->stats['rejected_30d']['sparkline']);
        // Each sparkline point is the element-wise sum (both fakes return zero-filled).
        foreach ($out->stats['pending']['sparkline'] as $point) {
            self::assertArrayHasKey('date', $point);
            self::assertArrayHasKey('value', $point);
            self::assertSame(0, $point['value']);
        }

        // avg_response_hours.sparkline is always empty.
        self::assertSame([], $out->stats['avg_response_hours']['sparkline']);
    }

    public function test_computes_avg_response_hours_as_weighted_average(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        // Stub repo: member side decided 3 apps in 30h total â†’ avg 10h.
        $memberRepo = $this->stubMemberRepoWithDecided(decidedCount: 3, decidedTotalHours: 30);
        // Stub repo: supporter side decided 0 apps in 0h.
        $supporterRepo = $this->stubSupporterRepoWithDecided(decidedCount: 0, decidedTotalHours: 0);

        $usecase = new ListApplicationsStats($memberRepo, $supporterRepo);
        $out     = $usecase->execute(new ListApplicationsStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertSame(10, $out->stats['avg_response_hours']['value']);
        self::assertSame([], $out->stats['avg_response_hours']['sparkline']);
    }

    public function test_avg_response_hours_zero_when_no_decisions(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $memberRepo    = new InMemoryMemberApplicationRepository();
        $supporterRepo = new InMemorySupporterApplicationRepository();

        $usecase = new ListApplicationsStats($memberRepo, $supporterRepo);
        $out     = $usecase->execute(new ListApplicationsStatsInput(acting: $admin, tenantId: $tenantId));

        // No decisions â†’ no divide-by-zero, value is 0.
        self::assertSame(0, $out->stats['avg_response_hours']['value']);
        self::assertSame([], $out->stats['avg_response_hours']['sparkline']);
    }

    public function test_throws_forbidden_for_non_admin(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $member   = ActingUserFactory::registeredInTenant(self::USER_ID, $tenantId);

        $this->expectException(ForbiddenException::class);

        $usecase = new ListApplicationsStats(
            new InMemoryMemberApplicationRepository(),
            new InMemorySupporterApplicationRepository(),
        );
        $usecase->execute(new ListApplicationsStatsInput(acting: $member, tenantId: $tenantId));
    }

    private function makeMemberApp(string $id, TenantId $tenantId, string $status): MemberApplication
    {
        return new MemberApplication(
            id:           MemberApplicationId::fromString($id),
            tenantId:     $tenantId,
            name:         'Test User',
            email:        'test-' . substr($id, -4) . '@example.com',
            dateOfBirth:  '1990-01-01',
            country:      'FI',
            motivation:   'I want to join.',
            howHeard:     null,
            status:       $status,
            createdAt:    null,
        );
    }

    private function makeSupporterApp(string $id, TenantId $tenantId, string $status): SupporterApplication
    {
        return new SupporterApplication(
            id:            SupporterApplicationId::fromString($id),
            tenantId:      $tenantId,
            orgName:       'Acme Org',
            contactPerson: 'Contact Person',
            regNo:         null,
            email:         'org-' . substr($id, -4) . '@example.com',
            country:       'FI',
            motivation:    'We support.',
            howHeard:      null,
            status:        $status,
            createdAt:     null,
        );
    }

    private function stubMemberRepoWithDecided(int $decidedCount, int $decidedTotalHours): MemberApplicationRepositoryInterface
    {
        return new class($decidedCount, $decidedTotalHours) implements MemberApplicationRepositoryInterface {
            public function __construct(
                private readonly int $decidedCount,
                private readonly int $decidedTotalHours,
            ) {}

            public function save(MemberApplication $application): void {}

            public function listPendingForTenant(TenantId $tenantId, int $limit): array
            {
                return [];
            }

            public function listDecidedForTenant(TenantId $tenantId, string $decision, int $limit, int $days = 30): array
            {
                return [];
            }

            public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication
            {
                return null;
            }

            public function findDetailedByIdForTenant(string $id, TenantId $tenantId): ?array
            {
                return null;
            }

            public function recordDecision(
                string $id,
                TenantId $tenantId,
                string $decision,
                UserId $decidedBy,
                ?string $note,
                DateTimeImmutable $decidedAt,
            ): void {}

            public function statsForTenant(TenantId $tenantId): array
            {
                $today = new DateTimeImmutable('today');
                $spark = [];
                for ($i = 29; $i >= 0; $i--) {
                    $spark[] = [
                        'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                        'value' => 0,
                    ];
                }

                return [
                    'pending'             => ['value' => 0, 'sparkline' => $spark],
                    'approved_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'rejected_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'decided_count'       => $this->decidedCount,
                    'decided_total_hours' => $this->decidedTotalHours,
                ];
            }

            public function notificationStatsForTenant(TenantId $tenantId): array
            {
                $today = new DateTimeImmutable('today');
                $spark = [];
                for ($i = 29; $i >= 0; $i--) {
                    $spark[] = [
                        'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                        'value' => 0,
                    ];
                }

                return [
                    'pending_count'           => 0,
                    'created_at_daily_30d'    => $spark,
                    'oldest_pending_age_days' => 0,
                ];
            }

            public function clearedDailyForTenant(TenantId $tenantId): array
            {
                $today = new DateTimeImmutable('today');
                $out   = [];
                for ($i = 29; $i >= 0; $i--) {
                    $out[] = [
                        'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                        'value' => 0,
                    ];
                }
                return $out;
            }
        };
    }

    private function stubSupporterRepoWithDecided(int $decidedCount, int $decidedTotalHours): SupporterApplicationRepositoryInterface
    {
        return new class($decidedCount, $decidedTotalHours) implements SupporterApplicationRepositoryInterface {
            public function __construct(
                private readonly int $decidedCount,
                private readonly int $decidedTotalHours,
            ) {}

            public function save(SupporterApplication $application): void {}

            public function listPendingForTenant(TenantId $tenantId, int $limit): array
            {
                return [];
            }

            public function listDecidedForTenant(TenantId $tenantId, string $decision, int $limit, int $days = 30): array
            {
                return [];
            }

            public function findByIdForTenant(string $id, TenantId $tenantId): ?SupporterApplication
            {
                return null;
            }

            public function findDetailedByIdForTenant(string $id, TenantId $tenantId): ?array
            {
                return null;
            }

            public function recordDecision(
                string $id,
                TenantId $tenantId,
                string $decision,
                UserId $decidedBy,
                ?string $note,
                DateTimeImmutable $decidedAt,
            ): void {}

            public function statsForTenant(TenantId $tenantId): array
            {
                $today = new DateTimeImmutable('today');
                $spark = [];
                for ($i = 29; $i >= 0; $i--) {
                    $spark[] = [
                        'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                        'value' => 0,
                    ];
                }

                return [
                    'pending'             => ['value' => 0, 'sparkline' => $spark],
                    'approved_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'rejected_30d'        => ['value' => 0, 'sparkline' => $spark],
                    'decided_count'       => $this->decidedCount,
                    'decided_total_hours' => $this->decidedTotalHours,
                ];
            }

            public function notificationStatsForTenant(TenantId $tenantId): array
            {
                $today = new DateTimeImmutable('today');
                $spark = [];
                for ($i = 29; $i >= 0; $i--) {
                    $spark[] = [
                        'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                        'value' => 0,
                    ];
                }

                return [
                    'pending_count'           => 0,
                    'created_at_daily_30d'    => $spark,
                    'oldest_pending_age_days' => 0,
                ];
            }

            public function clearedDailyForTenant(TenantId $tenantId): array
            {
                $today = new DateTimeImmutable('today');
                $out   = [];
                for ($i = 29; $i >= 0; $i--) {
                    $out[] = [
                        'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                        'value' => 0,
                    ];
                }
                return $out;
            }
        };
    }
}
