<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Support;

use DateTimeImmutable;
use Daems\Domain\Dismissal\AdminApplicationDismissal;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class InMemoryAdminApplicationDismissalRepository implements AdminApplicationDismissalRepositoryInterface
{
    /** @var list<AdminApplicationDismissal> */
    private array $rows = [];

    private int $autoSeq = 0;

    public function save(AdminApplicationDismissal $d): void
    {
        foreach ($this->rows as $i => $existing) {
            if ($existing->adminId === $d->adminId && $existing->appId === $d->appId) {
                $this->rows[$i] = $d;
                return;
            }
        }
        $this->rows[] = $d;
    }

    public function deleteByAdminId(string $adminId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (AdminApplicationDismissal $d): bool => $d->adminId !== $adminId
        ));
    }

    public function deleteByAppId(string $appId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (AdminApplicationDismissal $d): bool => $d->appId !== $appId
        ));
    }

    public function listAppIdsDismissedByAdmin(string $adminId): array
    {
        return array_values(array_map(
            static fn (AdminApplicationDismissal $d): string => $d->appId,
            array_filter(
                $this->rows,
                static fn (AdminApplicationDismissal $d): bool => $d->adminId === $adminId
            )
        ));
    }

    public function dismissedAppIdsFor(UserId $adminId): array
    {
        $id = $adminId->value();
        return array_values(array_map(
            static fn (AdminApplicationDismissal $d): string => $d->appId,
            array_filter(
                $this->rows,
                static fn (AdminApplicationDismissal $d): bool => $d->adminId === $id
            )
        ));
    }

    public function clearForAppIdAnyAdmin(TenantId $tenantId, string $appType, string $appId): void
    {
        // See SqlAdminApplicationDismissalRepository::clearForAppIdAnyAdmin — tenant_id is accepted for
        // forward-compat but not used for row selection; (app_type, app_id) is sufficient.
        unset($tenantId);
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (AdminApplicationDismissal $d): bool => !($d->appType === $appType && $d->appId === $appId)
        ));
    }

    /**
     * Test helper: record a dismissal by an admin for a given (appType, appId) tuple.
     * Tenant is accepted for semantic parity with the interface; it is not persisted
     * in this in-memory store because the backing table has no tenant_id column.
     */
    public function dismiss(TenantId $tenantId, string $adminId, string $appType, string $appId): void
    {
        unset($tenantId);
        $this->autoSeq++;
        $this->save(new AdminApplicationDismissal(
            id:           'inmem-' . $this->autoSeq,
            adminId:      $adminId,
            appId:        $appId,
            appType:      $appType,
            dismissedAt:  new DateTimeImmutable(),
        ));
    }

    /**
     * Test helper: has this admin dismissed this (appType, appId) tuple?
     */
    public function isDismissed(TenantId $tenantId, string $adminId, string $appType, string $appId): bool
    {
        unset($tenantId);
        foreach ($this->rows as $d) {
            if ($d->adminId === $adminId && $d->appType === $appType && $d->appId === $appId) {
                return true;
            }
        }
        return false;
    }
}
