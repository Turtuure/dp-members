<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\E2E;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F011_BackstageApplicationsAccessTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    public function testAnonymousReturns401(): void
    {
        $resp = $this->h->request('GET', '/api/v1/backstage/applications/pending');
        $this->assertSame(401, $resp->status());
    }

    public function testRegisteredUserReturns403(): void
    {
        $u = $this->h->seedUser('user@x.com', 'pass1234', 'registered');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending', $token);
        $this->assertSame(403, $resp->status());
    }

    public function testAdminReturns200(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/pending', $token);
        $this->assertSame(200, $resp->status());
    }
}
