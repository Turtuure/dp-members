<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\Members\ListMembersStats;

final class ListMembersStatsOutput
{
    /**
     * @param array{
     *   total_members: array{value: int, sparkline: list<array{date: string, value: int}>},
     *   new_members:   array{value: int, sparkline: list<array{date: string, value: int}>},
     *   supporters:    array{value: int, sparkline: list<array{date: string, value: int}>},
     *   inactive:      array{value: int, sparkline: list<array{date: string, value: int}>}
     * } $stats
     */
    public function __construct(public readonly array $stats) {}
}
