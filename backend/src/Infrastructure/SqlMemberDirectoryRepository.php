<?php

declare(strict_types=1);

namespace DaemsModule\Members\Infrastructure;

use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Backstage\MemberStatusAuditEntry;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class SqlMemberDirectoryRepository implements MemberDirectoryRepositoryInterface
{
    private const ALLOWED_SORT = ['member_number', 'name', 'joined_at', 'status'];
    private const ALLOWED_DIR  = ['ASC', 'DESC'];

    public function __construct(private readonly Connection $db) {}

    public function listMembersForTenant(
        TenantId $tenantId,
        array $filters,
        string $sort,
        string $dir,
        int $page,
        int $perPage,
    ): array {
        $sortSql = in_array($sort, self::ALLOWED_SORT, true) ? $sort : 'member_number';
        $dirSql  = in_array($dir, self::ALLOWED_DIR, true) ? $dir : 'ASC';

        $sortColumnMap = [
            'member_number' => 'u.member_number',
            'name'          => 'u.name',
            'joined_at'     => 'ut.joined_at',
            'status'        => 'u.membership_status',
        ];
        $sortCol = $sortColumnMap[$sortSql];

        $where  = ['ut.tenant_id = ?', 'ut.left_at IS NULL'];
        $params = [$tenantId->value()];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[]  = 'u.membership_status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['type']) && $filters['type'] !== '') {
            $where[]  = 'u.membership_type = ?';
            $params[] = $filters['type'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $where[]  = '(u.name LIKE ? OR u.email LIKE ? OR u.member_number LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);

        $countRow = $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             WHERE {$whereSql}",
            $params,
        );
        $totalRaw = $countRow['n'] ?? 0;
        $total = is_int($totalRaw) ? $totalRaw : (is_string($totalRaw) && is_numeric($totalRaw) ? (int) $totalRaw : 0);

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare(
            "SELECT u.id, u.name, u.email, u.membership_type, u.membership_status, u.member_number,
                    u.country, u.date_of_birth, u.created_at,
                    ut.role, ut.joined_at
             FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             WHERE {$whereSql}
             ORDER BY {$sortCol} {$dirSql}
             LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $entries = array_map(fn (array $r): MemberDirectoryEntry => $this->hydrateEntry($r), $rows);

        return ['entries' => $entries, 'total' => $total];
    }

    public function changeStatus(
        UserId $userId,
        TenantId $tenantId,
        string $newStatus,
        string $reason,
        UserId $performedBy,
        DateTimeImmutable $at,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $prevRow = $this->db->queryOne(
                'SELECT u.membership_status FROM users u
                 JOIN user_tenants ut ON ut.user_id = u.id
                 WHERE u.id = ? AND ut.tenant_id = ? AND ut.left_at IS NULL',
                [$userId->value(), $tenantId->value()],
            );
            if ($prevRow === null) {
                $pdo->rollBack();
                throw new \RuntimeException('Member not found in tenant');
            }
            $prevStatus = is_string($prevRow['membership_status'] ?? null) ? $prevRow['membership_status'] : null;

            $this->db->execute(
                'UPDATE users SET membership_status = ? WHERE id = ?',
                [$newStatus, $userId->value()],
            );

            $this->db->execute(
                'INSERT INTO member_status_audit
                    (id, tenant_id, user_id, previous_status, new_status, reason, performed_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid7::generate()->value(),
                    $tenantId->value(),
                    $userId->value(),
                    $prevStatus,
                    $newStatus,
                    $reason,
                    $performedBy->value(),
                    $at->format('Y-m-d H:i:s'),
                ],
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getAuditEntriesForMember(
        UserId $userId,
        TenantId $tenantId,
        int $limit,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.previous_status, a.new_status, a.reason, a.created_at,
                    COALESCE(performer.name, performer.email, a.performed_by) AS performed_by_name
             FROM member_status_audit a
             LEFT JOIN users performer ON performer.id = a.performed_by
             WHERE a.user_id = ? AND a.tenant_id = ?
             ORDER BY a.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId->value());
        $stmt->bindValue(2, $tenantId->value());
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn (array $r): MemberStatusAuditEntry => new MemberStatusAuditEntry(
            id:              self::str($r, 'id'),
            previousStatus:  is_string($r['previous_status'] ?? null) ? $r['previous_status'] : null,
            newStatus:       self::str($r, 'new_status'),
            reason:          self::str($r, 'reason'),
            performedByName: self::str($r, 'performed_by_name'),
            createdAt:       self::str($r, 'created_at'),
        ), $rows);
    }

    /** @param array<string, mixed> $r */
    private function hydrateEntry(array $r): MemberDirectoryEntry
    {
        $memberNumber = $r['member_number'] ?? null;
        $role         = $r['role'] ?? null;
        $country      = $r['country'] ?? null;
        $dateOfBirth  = $r['date_of_birth'] ?? null;

        return new MemberDirectoryEntry(
            userId:           self::str($r, 'id'),
            name:             self::str($r, 'name'),
            email:            self::str($r, 'email'),
            membershipType:   self::str($r, 'membership_type'),
            membershipStatus: self::str($r, 'membership_status'),
            memberNumber:     is_string($memberNumber) ? $memberNumber : null,
            roleInTenant:     is_string($role) ? $role : null,
            joinedAt:         self::str($r, 'joined_at'),
            country:          is_string($country) ? $country : null,
            dateOfBirth:      is_string($dateOfBirth) ? $dateOfBirth : null,
            createdAt:        self::str($r, 'created_at'),
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

