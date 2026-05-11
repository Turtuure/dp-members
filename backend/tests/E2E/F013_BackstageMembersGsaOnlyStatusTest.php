<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\E2E;

use Daems\Domain\Backstage\MemberDirectoryEntry;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F013_BackstageMembersGsaOnlyStatusTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    public function testTenantAdminCannotChangeMemberStatus(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $targetId = UserId::generate()->value();
        $this->h->memberDirectory->entries[] = new MemberDirectoryEntry(
            $targetId, 'Target', 't@x.com', 'individual', 'active', '1001', 'member', '2026-01-01 00:00:00', null, null, '2026-01-01 00:00:00', null,
        );

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/members/' . $targetId . '/status', $token, [
            'status' => 'suspended',
            'reason' => 'test',
        ]);

        $this->assertSame(403, $resp->status());
    }

    public function testGsaCanChangeMemberStatus(): void
    {
        $gsa = $this->h->seedPlatformAdmin('gsa@x.com', 'pass1234');
        $token = $this->h->tokenFor($gsa);

        $targetId = UserId::generate()->value();
        $this->h->memberDirectory->entries[] = new MemberDirectoryEntry(
            $targetId, 'Target2', 't2@x.com', 'individual', 'active', '1002', 'member', '2026-01-01 00:00:00', null, null, '2026-01-01 00:00:00', null,
        );

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/members/' . $targetId . '/status', $token, [
            'status' => 'suspended',
            'reason' => 'payment overdue',
        ]);

        $this->assertSame(200, $resp->status());
        $this->assertCount(1, $this->h->memberDirectory->audit[$targetId] ?? []);
    }
}
