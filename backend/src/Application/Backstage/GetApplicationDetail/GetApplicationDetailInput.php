<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\GetApplicationDetail;

use Daems\Domain\Auth\ActingUser;

final class GetApplicationDetailInput
{
    /**
     * @param 'member'|'supporter' $type
     */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $type,
        public readonly string $id,
    ) {}
}
