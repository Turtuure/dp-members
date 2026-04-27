<?php

declare(strict_types=1);

namespace DaemsModule\Members\Infrastructure;

use Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface;
use PDO;
use RuntimeException;

final class SqlTenantSupporterCounterRepository implements TenantSupporterCounterRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function allocateNext(string $tenantId): string
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO tenant_supporter_counters (tenant_id, next_value) VALUES (?, 1)'
        )->execute([$tenantId]);

        $select = $this->pdo->prepare(
            'SELECT next_value FROM tenant_supporter_counters WHERE tenant_id = ? FOR UPDATE'
        );
        $select->execute([$tenantId]);
        /** @var array<string, mixed>|false $row */
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('tenant_supporter_counter_missing');
        }
        $rawNext = $row['next_value'];
        $next = is_numeric($rawNext) ? (int) $rawNext : throw new RuntimeException('tenant_supporter_counter_invalid');

        $this->pdo->prepare(
            'UPDATE tenant_supporter_counters SET next_value = next_value + 1 WHERE tenant_id = ?'
        )->execute([$tenantId]);

        return str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}

