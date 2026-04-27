<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListPendingApplications;

use Daems\Domain\Auth\ActingUser;

final class ListPendingApplicationsForAdminInput
{
    public function __construct(public readonly ActingUser $acting) {}
}
