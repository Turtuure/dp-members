<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\DismissApplication;

use Daems\Domain\Auth\ActingUser;

final class DismissApplicationInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $appId,
        public readonly string $appType, // 'member' | 'supporter' | 'project_proposal' | 'forum_report'
    ) {}
}
