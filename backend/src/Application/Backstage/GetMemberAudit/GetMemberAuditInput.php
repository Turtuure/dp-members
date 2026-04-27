<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\GetMemberAudit;

use Daems\Domain\Auth\ActingUser;

final class GetMemberAuditInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $memberId,
        public readonly int $limit = 25,
    ) {}
}
