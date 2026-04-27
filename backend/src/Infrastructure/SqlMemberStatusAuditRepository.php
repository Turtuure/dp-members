<?php

declare(strict_types=1);

namespace DaemsModule\Members\Infrastructure;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlMemberStatusAuditRepository implements MemberStatusAuditRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(MemberStatusAudit $audit): void
    {
        $this->db->execute(
            'INSERT INTO member_status_audit
                (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $audit->id,
                $audit->tenantId,
                $audit->userId,
                $audit->previousStatus,
                $audit->newStatus,
                $audit->reason,
                $audit->performedByAdminId,
                $audit->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return list<array{date: string, value: int}>
     */
    public function dailyTransitionsForTenant(TenantId $tenantId, string $newStatus): array
    {
        // Sparkline template: 30 entries, today = last entry.
        $base = new \DateTimeImmutable('today');
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[$base->modify("-{$i} days")->format('Y-m-d')] = 0;
        }

        $rows = $this->db->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n
             FROM member_status_audit
             WHERE tenant_id = ? AND new_status = ?
               AND created_at >= (CURDATE() - INTERVAL 29 DAY)
             GROUP BY DATE(created_at)',
            [$tenantId->value(), $newStatus],
        );

        foreach ($rows as $row) {
            $d = is_string($row['d'] ?? null) ? (string) $row['d'] : '';
            if ($d === '' || !isset($days[$d])) {
                continue;
            }
            $n = $row['n'] ?? null;
            if (is_int($n)) {
                $days[$d] += $n;
            } elseif (is_string($n) && is_numeric($n)) {
                $days[$d] += (int) $n;
            }
        }

        $out = [];
        foreach ($days as $date => $value) {
            $out[] = ['date' => $date, 'value' => $value];
        }
        return $out;
    }
}

