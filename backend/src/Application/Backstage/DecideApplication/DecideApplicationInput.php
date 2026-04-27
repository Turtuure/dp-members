<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\DecideApplication;

use Daems\Domain\Auth\ActingUser;

final class DecideApplicationInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $type,       // 'member' | 'supporter'
        public readonly string $id,
        public readonly string $decision,   // 'approved' | 'rejected'
        public readonly ?string $note,
    ) {}
}
