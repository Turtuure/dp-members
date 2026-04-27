<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListPendingApplications;

final class ListPendingApplicationsForAdminOutput
{
    /** @param list<array{id:string,type:string,name:string,created_at:string}> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {}

    /** @return array{items: list<array{id:string,type:string,name:string,created_at:string}>, total: int} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total];
    }
}
