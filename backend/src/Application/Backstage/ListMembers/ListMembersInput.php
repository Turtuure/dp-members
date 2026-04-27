<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListMembers;

use Daems\Domain\Auth\ActingUser;

final class ListMembersInput
{
    /** @param array{status?:string, type?:string, q?:string} $filters */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly array $filters = [],
        public readonly string $sort = 'member_number',
        public readonly string $dir = 'ASC',
        public readonly int $page = 1,
        public readonly int $perPage = 50,
    ) {}
}
