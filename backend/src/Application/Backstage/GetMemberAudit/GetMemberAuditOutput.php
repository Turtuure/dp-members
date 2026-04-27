<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\GetMemberAudit;

use Daems\Domain\Backstage\MemberStatusAuditEntry;

final class GetMemberAuditOutput
{
    /** @param list<MemberStatusAuditEntry> $entries */
    public function __construct(public readonly array $entries) {}

    /** @return list<array<string, string|null>> */
    public function toArray(): array
    {
        return array_map(static fn (MemberStatusAuditEntry $e) => $e->toArray(), $this->entries);
    }
}
