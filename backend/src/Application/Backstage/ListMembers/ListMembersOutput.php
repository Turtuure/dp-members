<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListMembers;

use Daems\Domain\Backstage\MemberDirectoryEntry;

final class ListMembersOutput
{
    /**
     * @param list<MemberDirectoryEntry> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * @return array{
     *   items: list<array<string, string|null>>,
     *   total: int,
     *   page: int,
     *   per_page: int
     * }
     */
    public function toArray(): array
    {
        return [
            'items'    => array_map(static fn (MemberDirectoryEntry $e) => $e->toArray(), $this->entries),
            'total'    => $this->total,
            'page'     => $this->page,
            'per_page' => $this->perPage,
        ];
    }
}
