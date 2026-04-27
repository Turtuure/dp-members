<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Support;

use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use DateTimeImmutable;

final class InMemorySupporterApplicationRepository implements SupporterApplicationRepositoryInterface
{
    /** @var list<SupporterApplication> */
    public array $applications = [];

    /** @var array<string, array{decision:string, by:string, note:?string, at:string}> */
    public array $decisions = [];

    public function save(SupporterApplication $application): void
    {
        $this->applications[] = $application;
    }

    public function listPendingForTenant(\Daems\Domain\Tenant\TenantId $tenantId, int $limit): array
    {
        $filtered = array_filter(
            $this->applications,
            static fn (SupporterApplication $a): bool => $a->tenantId()->equals($tenantId) && $a->status() === 'pending',
        );
        return array_slice(array_values($filtered), 0, $limit);
    }

    public function listDecidedForTenant(\Daems\Domain\Tenant\TenantId $tenantId, string $decision, int $limit, int $days = 30): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("decision must be 'approved' or 'rejected', got: {$decision}");
        }

        // The fake doesn't track decided_at timestamps with day granularity, so $days
        // is accepted but not enforced — tests assert payload shape, not date-window
        // exclusion. Newest decisions surface first via reverse iteration.
        $out = [];
        foreach (array_reverse($this->decisions, true) as $appId => $d) {
            if ($d['decision'] !== $decision) {
                continue;
            }
            $found = null;
            foreach ($this->applications as $a) {
                if ($a->id()->value() === $appId && $a->tenantId()->equals($tenantId)) {
                    $found = $a;
                    break;
                }
            }
            if ($found === null) {
                continue;
            }
            $out[] = [
                'id'             => $found->id()->value(),
                'org_name'       => $found->orgName(),
                'contact_person' => $found->contactPerson(),
                'email'          => $found->email(),
                'country'        => $found->country(),
                'motivation'     => $found->motivation(),
                'decided_at'     => $d['at'],
                'decision_note'  => $d['note'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function findByIdForTenant(string $id, \Daems\Domain\Tenant\TenantId $tenantId): ?SupporterApplication
    {
        foreach ($this->applications as $a) {
            if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
                return $a;
            }
        }
        return null;
    }

    public function findDetailedByIdForTenant(string $id, \Daems\Domain\Tenant\TenantId $tenantId): ?array
    {
        foreach ($this->applications as $a) {
            if ($a->id()->value() !== $id || !$a->tenantId()->equals($tenantId)) {
                continue;
            }
            $decision = $this->decisions[$id] ?? null;
            return [
                'id'             => $a->id()->value(),
                'org_name'       => $a->orgName(),
                'contact_person' => $a->contactPerson(),
                'reg_no'         => $a->regNo(),
                'email'          => $a->email(),
                'country'        => $a->country(),
                'motivation'     => $a->motivation(),
                'how_heard'      => $a->howHeard(),
                'status'         => $a->status(),
                'created_at'     => $a->createdAt(),
                'decided_at'     => $decision !== null ? $decision['at']   : null,
                'decision_note'  => $decision !== null ? $decision['note'] : null,
            ];
        }
        return null;
    }

    public function recordDecision(
        string $id,
        \Daems\Domain\Tenant\TenantId $tenantId,
        string $decision,
        \Daems\Domain\User\UserId $decidedBy,
        ?string $note,
        DateTimeImmutable $decidedAt,
    ): void {
        foreach ($this->applications as $i => $a) {
            if ($a->id()->value() === $id && $a->tenantId()->equals($tenantId)) {
                $this->applications[$i] = new SupporterApplication(
                    $a->id(), $a->tenantId(), $a->orgName(), $a->contactPerson(), $a->regNo(),
                    $a->email(), $a->country(), $a->motivation(), $a->howHeard(),
                    $decision, $a->createdAt(),
                );
                $this->decisions[$id] = ['decision' => $decision, 'by' => $decidedBy->value(), 'note' => $note, 'at' => $decidedAt->format('Y-m-d H:i:s')];
                return;
            }
        }
    }

    public function statsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        // The fake doesn't track created_at / decided_at timestamps with daily granularity,
        // so derive simple value counts from the in-memory state and emit zero-filled
        // sparklines. E2E/use-case tests assert shape + values, not per-day buckets.
        $pending = 0;
        foreach ($this->applications as $a) {
            if ($a->tenantId()->equals($tenantId) && $a->status() === 'pending') {
                $pending++;
            }
        }

        $approved          = 0;
        $rejected          = 0;
        $decidedCount      = 0;
        $decidedTotalHours = 0;
        foreach ($this->decisions as $appId => $d) {
            // Only count decisions whose underlying application belongs to this tenant.
            $found = null;
            foreach ($this->applications as $a) {
                if ($a->id()->value() === $appId && $a->tenantId()->equals($tenantId)) {
                    $found = $a;
                    break;
                }
            }
            if ($found === null) {
                continue;
            }
            if ($d['decision'] === 'approved') {
                $approved++;
            } elseif ($d['decision'] === 'rejected') {
                $rejected++;
            }
            $decidedCount++;
        }

        $today      = new \DateTimeImmutable('today');
        $emptySpark = [];
        for ($i = 29; $i >= 0; $i--) {
            $emptySpark[] = [
                'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                'value' => 0,
            ];
        }

        return [
            'pending'             => ['value' => $pending,  'sparkline' => $emptySpark],
            'approved_30d'        => ['value' => $approved, 'sparkline' => $emptySpark],
            'rejected_30d'        => ['value' => $rejected, 'sparkline' => $emptySpark],
            'decided_count'       => $decidedCount,
            'decided_total_hours' => $decidedTotalHours,
        ];
    }

    public function notificationStatsForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        // Derive pending count from in-memory state. Sparkline + oldest-age are
        // zero-stubs (the fake doesn't track per-day created_at granularity).
        $pending = 0;
        foreach ($this->applications as $a) {
            if ($a->tenantId()->equals($tenantId) && $a->status() === 'pending') {
                $pending++;
            }
        }

        $today  = new \DateTimeImmutable('today');
        $spark  = [];
        for ($i = 29; $i >= 0; $i--) {
            $spark[] = [
                'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                'value' => 0,
            ];
        }

        return [
            'pending_count'           => $pending,
            'created_at_daily_30d'    => $spark,
            'oldest_pending_age_days' => 0,
        ];
    }

    public function clearedDailyForTenant(\Daems\Domain\Tenant\TenantId $tenantId): array
    {
        // The fake doesn't track per-day decided_at granularity; emit a 30-entry
        // zero-filled backward series. The cleared_30d KPI is summed across the 4
        // sources at the use case layer — per-source InMemory state isn't worth
        // deriving here.
        $today = new \DateTimeImmutable('today');
        $out   = [];
        for ($i = 29; $i >= 0; $i--) {
            $out[] = [
                'date'  => $today->modify('-' . $i . ' days')->format('Y-m-d'),
                'value' => 0,
            ];
        }
        return $out;
    }
}
