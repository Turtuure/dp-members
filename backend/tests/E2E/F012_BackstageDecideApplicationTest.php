<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\E2E;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class F012_BackstageDecideApplicationTest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
    }

    public function testAdminCanApproveMemberApplication(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->h->testTenantId,
            'Alice', 'a@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );
        $this->h->memberApps->save($app);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/applications/member/' . $app->id()->value() . '/decision', $token, [
            'decision' => 'approved',
            'note'     => 'welcome',
        ]);

        $this->assertSame(200, $resp->status());
        $decisions = $this->h->memberApps->decisions;
        $this->assertArrayHasKey($app->id()->value(), $decisions);
        $this->assertSame('approved', $decisions[$app->id()->value()]['decision']);
    }

    public function testApplicationNotFoundReturns404(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/applications/member/unknown-id/decision', $token, [
            'decision' => 'approved',
        ]);
        $this->assertSame(404, $resp->status());
    }

    public function testInvalidDecisionReturns422(): void
    {
        $admin = $this->h->seedUser('admin@x.com', 'pass1234', 'admin');
        $token = $this->h->tokenFor($admin);

        $app = new MemberApplication(
            MemberApplicationId::generate(), $this->h->testTenantId,
            'Bob', 'b@x.com', '1990-01-01', null, 'motive', null, 'pending',
        );
        $this->h->memberApps->save($app);

        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/applications/member/' . $app->id()->value() . '/decision', $token, [
            'decision' => 'maybe',
        ]);
        $this->assertSame(422, $resp->status());
    }
}
