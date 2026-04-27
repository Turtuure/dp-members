<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Support;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;

final class InMemoryMemberStatusAuditRepository implements MemberStatusAuditRepositoryInterface
{
    /** @var list<MemberStatusAudit> */
    private array $rows = [];

    public function save(MemberStatusAudit $audit): void
    {
        $this->rows[] = $audit;
    }

    /** @return list<MemberStatusAudit> */
    public function allForTenant(string $tenantId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (MemberStatusAudit $a): bool => $a->tenantId === $tenantId,
        ));
    }

    /**
     * @return list<array{date: string, value: int}>
     */
    public function dailyTransitionsForTenant(TenantId $tenantId, string $newStatus): array
    {
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }

        $tid = $tenantId->value();
        foreach ($this->rows as $audit) {
            if ($audit->tenantId !== $tid || $audit->newStatus !== $newStatus) {
                continue;
            }
            $key = $audit->createdAt->format('Y-m-d');
            if (isset($days[$key])) {
                $days[$key]++;
            }
        }

        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }
}
