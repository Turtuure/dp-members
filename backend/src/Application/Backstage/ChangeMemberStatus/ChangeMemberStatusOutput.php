<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ChangeMemberStatus;

final class ChangeMemberStatusOutput
{
    public function __construct(
        public readonly bool $success,
    ) {}

    /** @return array{success: bool} */
    public function toArray(): array
    {
        return ['success' => $this->success];
    }
}
