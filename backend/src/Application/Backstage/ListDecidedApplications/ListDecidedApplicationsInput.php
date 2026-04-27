<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListDecidedApplications;

use Daems\Domain\Auth\ActingUser;

final class ListDecidedApplicationsInput
{
    /**
     * @param 'approved'|'rejected' $decision
     */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $decision,
        public readonly int $limit = 200,
        public readonly int $days = 30,
    ) {}
}
