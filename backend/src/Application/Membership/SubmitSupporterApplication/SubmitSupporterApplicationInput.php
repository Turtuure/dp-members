<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Membership\SubmitSupporterApplication;

use Daems\Domain\Tenant\TenantId;

final class SubmitSupporterApplicationInput
{
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $orgName,
        public readonly string $contactPerson,
        public readonly ?string $regNo,
        public readonly string $email,
        public readonly ?string $country,
        public readonly string $motivation,
        public readonly ?string $howHeard,
    ) {}
}
