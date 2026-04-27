<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Support;

use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Backstage\MemberStatusAuditEntry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class InMemoryMemberDirectoryRepository implements MemberDirectoryRepositoryInterface
{
    /** @var list<MemberDirectoryEntry> */
    public array $entries = [];

    /** @var array<string, list<MemberStatusAuditEntry>> by user_id */
    public array $audit = [];

    public function listMembersForTenant(
        TenantId $tenantId,
        array $filters,
        string $sort,
        string $dir,
        int $page,
        int $perPage,
    ): array {
        $matches = $this->entries;

        if (isset($filters['status']) && $filters['status'] !== '') {
            $matches = array_values(array_filter($matches, static fn (MemberDirectoryEntry $e): bool => $e->membershipStatus === $filters['status']));
        }
        if (isset($filters['type']) && $filters['type'] !== '') {
            $matches = array_values(array_filter($matches, static fn (MemberDirectoryEntry $e): bool => $e->membershipType === $filters['type']));
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $q = (string) $filters['q'];
            $matches = array_values(array_filter($matches, static function (MemberDirectoryEntry $e) use ($q): bool {
                return str_contains($e->name, $q) || str_contains($e->email, $q) || ($e->memberNumber !== null && str_contains($e->memberNumber, $q));
            }));
        }

        $total  = count($matches);
        $offset = max(0, ($page - 1) * $perPage);

        return ['entries' => array_slice($matches, $offset, $perPage), 'total' => $total];
    }

    public function changeStatus(
        UserId $userId,
        TenantId $tenantId,
        string $newStatus,
        string $reason,
        UserId $performedBy,
        DateTimeImmutable $at,
    ): void {
        $prev = null;
        foreach ($this->entries as $i => $e) {
            if ($e->userId === $userId->value()) {
                $prev = $e->membershipStatus;
                $this->entries[$i] = new MemberDirectoryEntry(
                    $e->userId, $e->name, $e->email, $e->membershipType,
                    $newStatus, $e->memberNumber, $e->roleInTenant, $e->joinedAt,
                    $e->country, $e->dateOfBirth, $e->createdAt,
                );
                break;
            }
        }
        $this->audit[$userId->value()][] = new MemberStatusAuditEntry(
            id:              'audit-' . count($this->audit[$userId->value()] ?? []),
            previousStatus:  $prev,
            newStatus:       $newStatus,
            reason:          $reason,
            performedByName: $performedBy->value(),
            createdAt:       $at->format('Y-m-d H:i:s'),
        );
    }

    public function getAuditEntriesForMember(UserId $userId, TenantId $tenantId, int $limit): array
    {
        $list = array_reverse($this->audit[$userId->value()] ?? []);
        return array_slice($list, 0, $limit);
    }
}
