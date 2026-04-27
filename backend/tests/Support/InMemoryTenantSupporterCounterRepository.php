<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Support;

use Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface;

final class InMemoryTenantSupporterCounterRepository implements TenantSupporterCounterRepositoryInterface
{
    /** @var array<string, int> */
    private array $counters = [];

    public function allocateNext(string $tenantId): string
    {
        $next = $this->counters[$tenantId] ?? 1;
        $this->counters[$tenantId] = $next + 1;
        return str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function setNextForTesting(string $tenantId, int $value): void
    {
        $this->counters[$tenantId] = $value;
    }
}
