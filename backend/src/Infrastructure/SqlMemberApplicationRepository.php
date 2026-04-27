<?php

declare(strict_types=1);

namespace DaemsModule\Members\Infrastructure;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Adapter\Persistence\Sql\Concerns\DailyStatsHelpers;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlMemberApplicationRepository implements MemberApplicationRepositoryInterface
{
    use DailyStatsHelpers;

    public function __construct(private readonly Connection $db) {}

    public function save(MemberApplication $app): void
    {
        $this->db->execute(
            'INSERT INTO member_applications
                (id, tenant_id, name, email, date_of_birth, country, motivation, how_heard, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $app->id()->value(),
                $app->tenantId()->value(),
                $app->name(),
                $app->email(),
                $app->dateOfBirth(),
                $app->country(),
                $app->motivation(),
                $app->howHeard(),
                $app->status(),
            ],
        );
    }

    public function listPendingForTenant(TenantId $tenantId, int $limit): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM member_applications
             WHERE tenant_id = ? AND status = 'pending'
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId->value());
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $r): MemberApplication => $this->hydrate($r), $rows);
    }

    public function listDecidedForTenant(TenantId $tenantId, string $decision, int $limit, int $days = 30): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("decision must be 'approved' or 'rejected', got: {$decision}");
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT id, name, email, date_of_birth, country, motivation, decided_at, decision_note
               FROM member_applications
              WHERE tenant_id = ?
                AND status = ?
                AND decided_at IS NOT NULL
                AND decided_at >= (NOW() - INTERVAL ? DAY)
              ORDER BY decided_at DESC
              LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId->value());
        $stmt->bindValue(2, $decision);
        $stmt->bindValue(3, $days, \PDO::PARAM_INT);
        $stmt->bindValue(4, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            $country = $r['country'] ?? null;
            $note    = $r['decision_note'] ?? null;
            return [
                'id'            => self::str($r, 'id'),
                'name'          => self::str($r, 'name'),
                'email'         => self::str($r, 'email'),
                'date_of_birth' => self::str($r, 'date_of_birth'),
                'country'       => is_string($country) ? $country : null,
                'motivation'    => self::str($r, 'motivation'),
                'decided_at'    => self::str($r, 'decided_at'),
                'decision_note' => is_string($note) ? $note : null,
            ];
        }, $rows);
    }

    public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication
    {
        $row = $this->db->queryOne(
            'SELECT * FROM member_applications WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findDetailedByIdForTenant(string $id, TenantId $tenantId): ?array
    {
        $row = $this->db->queryOne(
            'SELECT id, name, email, date_of_birth, country, motivation, how_heard, status,
                    created_at, decided_at, decision_note
               FROM member_applications
              WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId->value()],
        );
        if ($row === null) {
            return null;
        }
        $country    = $row['country']       ?? null;
        $howHeard   = $row['how_heard']     ?? null;
        $createdAt  = $row['created_at']    ?? null;
        $decidedAt  = $row['decided_at']    ?? null;
        $note       = $row['decision_note'] ?? null;
        return [
            'id'            => self::str($row, 'id'),
            'name'          => self::str($row, 'name'),
            'email'         => self::str($row, 'email'),
            'date_of_birth' => self::str($row, 'date_of_birth'),
            'country'       => is_string($country) ? $country : null,
            'motivation'    => self::str($row, 'motivation'),
            'how_heard'     => is_string($howHeard) ? $howHeard : null,
            'status'        => self::str($row, 'status'),
            'created_at'    => is_string($createdAt) ? $createdAt : null,
            'decided_at'    => is_string($decidedAt) ? $decidedAt : null,
            'decision_note' => is_string($note) ? $note : null,
        ];
    }

    public function recordDecision(
        string $id,
        TenantId $tenantId,
        string $decision,
        UserId $decidedBy,
        ?string $note,
        \DateTimeImmutable $decidedAt,
    ): void {
        $this->db->execute(
            'UPDATE member_applications
                SET status = ?, decided_at = ?, decided_by = ?, decision_note = ?
              WHERE id = ? AND tenant_id = ?',
            [
                $decision,
                $decidedAt->format('Y-m-d H:i:s'),
                $decidedBy->value(),
                $note,
                $id,
                $tenantId->value(),
            ],
        );

        // Audit entry to member_register_audit (application-scoped)
        $this->db->execute(
            'INSERT INTO member_register_audit (id, tenant_id, application_id, action, performed_by, note)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                \Daems\Domain\Shared\ValueObject\Uuid7::generate()->value(),
                $tenantId->value(),
                $id,
                $decision,
                $decidedBy->value(),
                $note,
            ],
        );
    }

    public function statsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Single roundtrip: pending count + 30d approved/rejected counts + avg helpers.
        $totalsRow = $this->db->queryOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'approved' AND decided_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS approved_30d,
                SUM(CASE WHEN status = 'rejected' AND decided_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS rejected_30d,
                SUM(CASE WHEN decided_at IS NOT NULL AND decided_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS decided_count,
                COALESCE(SUM(CASE WHEN decided_at IS NOT NULL AND decided_at >= (NOW() - INTERVAL 30 DAY)
                                  THEN TIMESTAMPDIFF(HOUR, created_at, decided_at) ELSE 0 END), 0) AS decided_total_hours
             FROM member_applications
             WHERE tenant_id = ?",
            [$tid],
        ) ?? [];

        $pendingValue       = self::asStatsInt($totalsRow, 'pending');
        $approvedValue      = self::asStatsInt($totalsRow, 'approved_30d');
        $rejectedValue      = self::asStatsInt($totalsRow, 'rejected_30d');
        $decidedCount       = self::asStatsInt($totalsRow, 'decided_count');
        $decidedTotalHours  = self::asStatsInt($totalsRow, 'decided_total_hours');

        // Pending sparkline (created_at, status = 'pending').
        $pendingRows = $this->db->query(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ? AND status = 'pending'
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)",
            [$tid],
        );
        $pendingSpark = self::buildDailySeries30dBackward($pendingRows);

        // Approved sparkline (decided_at, status = 'approved').
        $approvedRows = $this->db->query(
            "SELECT DATE(decided_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ? AND status = 'approved'
                AND decided_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(decided_at)",
            [$tid],
        );
        $approvedSpark = self::buildDailySeries30dBackward($approvedRows);

        // Rejected sparkline (decided_at, status = 'rejected').
        $rejectedRows = $this->db->query(
            "SELECT DATE(decided_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ? AND status = 'rejected'
                AND decided_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(decided_at)",
            [$tid],
        );
        $rejectedSpark = self::buildDailySeries30dBackward($rejectedRows);

        return [
            'pending'             => ['value' => $pendingValue,  'sparkline' => $pendingSpark],
            'approved_30d'        => ['value' => $approvedValue, 'sparkline' => $approvedSpark],
            'rejected_30d'        => ['value' => $rejectedValue, 'sparkline' => $rejectedSpark],
            'decided_count'       => $decidedCount,
            'decided_total_hours' => $decidedTotalHours,
        ];
    }

    public function notificationStatsForTenant(TenantId $tenantId): array
    {
        $tid = $tenantId->value();

        // Single roundtrip: pending count + oldest pending age (days).
        $countRow = $this->db->queryOne(
            "SELECT COUNT(*) AS n,
                    COALESCE(MAX(DATEDIFF(NOW(), created_at)), 0) AS oldest
               FROM member_applications
              WHERE tenant_id = ? AND status = 'pending'",
            [$tid],
        ) ?? [];

        $pendingCount = self::asStatsInt($countRow, 'n');
        $oldestAge    = self::asStatsInt($countRow, 'oldest');

        // Sparkline: 30-day BACKWARD by created_at, ALL statuses (incoming volume).
        $rows = $this->db->query(
            'SELECT DATE(created_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ?
                AND created_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(created_at)',
            [$tid],
        );
        $sparkline = self::buildDailySeries30dBackward($rows);

        return [
            'pending_count'           => $pendingCount,
            'created_at_daily_30d'    => $sparkline,
            'oldest_pending_age_days' => $oldestAge,
        ];
    }

    public function clearedDailyForTenant(TenantId $tenantId): array
    {
        $rows = $this->db->query(
            'SELECT DATE(decided_at) AS d, COUNT(*) AS n
               FROM member_applications
              WHERE tenant_id = ?
                AND decided_at IS NOT NULL
                AND decided_at >= (CURDATE() - INTERVAL 29 DAY)
              GROUP BY DATE(decided_at)',
            [$tenantId->value()],
        );
        return self::buildDailySeries30dBackward($rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): MemberApplication
    {
        $country  = $row['country'] ?? null;
        $howHeard = $row['how_heard'] ?? null;

        $createdAt = $row['created_at'] ?? null;

        return new MemberApplication(
            MemberApplicationId::fromString(self::str($row, 'id')),
            TenantId::fromString(self::str($row, 'tenant_id')),
            self::str($row, 'name'),
            self::str($row, 'email'),
            self::str($row, 'date_of_birth'),
            is_string($country) ? $country : null,
            self::str($row, 'motivation'),
            is_string($howHeard) ? $howHeard : null,
            self::str($row, 'status'),
            is_string($createdAt) ? $createdAt : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }
}

