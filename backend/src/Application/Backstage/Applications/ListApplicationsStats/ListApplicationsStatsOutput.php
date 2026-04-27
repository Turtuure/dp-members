<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats;

final class ListApplicationsStatsOutput
{
    /**
     * @param array{
     *   pending:             array{value: int, sparkline: list<array{date: string, value: int}>},
     *   approved_30d:        array{value: int, sparkline: list<array{date: string, value: int}>},
     *   rejected_30d:        array{value: int, sparkline: list<array{date: string, value: int}>},
     *   avg_response_hours:  array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
