<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

/**
 * Combines per-domain KPI slices from MemberApplication + SupporterApplication
 * into the unified 4-KPI strip rendered by /backstage/applications.
 *
 * Element-wise summed: pending, approved_30d, rejected_30d.
 * Computed: avg_response_hours (weighted across both decision pools, hours per decision).
 */
final class ListApplicationsStats
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
    ) {}

    public function execute(ListApplicationsStatsInput $input): ListApplicationsStatsOutput
    {
        if (!$input->acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException('forbidden');
        }

        $m = $this->memberApps->statsForTenant($input->tenantId);
        $s = $this->supporterApps->statsForTenant($input->tenantId);

        $totalDecided = $m['decided_count'] + $s['decided_count'];
        $totalHours   = $m['decided_total_hours'] + $s['decided_total_hours'];
        $avgHours     = $totalDecided > 0 ? (int) round($totalHours / $totalDecided) : 0;

        return new ListApplicationsStatsOutput(stats: [
            'pending'            => self::sumKpi($m['pending'],      $s['pending']),
            'approved_30d'       => self::sumKpi($m['approved_30d'], $s['approved_30d']),
            'rejected_30d'       => self::sumKpi($m['rejected_30d'], $s['rejected_30d']),
            'avg_response_hours' => ['value' => $avgHours, 'sparkline' => []],
        ]);
    }

    /**
     * Element-wise sum of two KPI structures (value + sparkline). Sparklines are
     * assumed to share the same date sequence — both repos use an identical
     * 30-day backward window keyed by today.
     *
     * @param array{value: int, sparkline: list<array{date: string, value: int}>} $a
     * @param array{value: int, sparkline: list<array{date: string, value: int}>} $b
     * @return array{value: int, sparkline: list<array{date: string, value: int}>}
     */
    private static function sumKpi(array $a, array $b): array
    {
        $merged = [];
        foreach ($a['sparkline'] as $i => $point) {
            $merged[] = [
                'date'  => $point['date'],
                'value' => $point['value'] + ($b['sparkline'][$i]['value'] ?? 0),
            ];
        }
        return ['value' => $a['value'] + $b['value'], 'sparkline' => $merged];
    }
}
