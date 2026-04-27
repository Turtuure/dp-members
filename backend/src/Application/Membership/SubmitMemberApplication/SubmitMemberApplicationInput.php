<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Membership\SubmitMemberApplication;

use Daems\Domain\Tenant\TenantId;

final class SubmitMemberApplicationInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $dateOfBirth,
        public readonly ?string $country,
        public readonly string $motivation,
        public readonly ?string $howHeard,
    ) {}
}
