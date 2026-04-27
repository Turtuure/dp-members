<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Membership\SubmitMemberApplication;

final class SubmitMemberApplicationOutput
{
    public function __construct(
        public readonly string $id,
    ) {}
}
