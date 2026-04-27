<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\E2E\Backstage;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class MembersStatsEndpointTest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h          = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
        $admin            = $this->h->seedUser('admin-membersstats@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_admin_gets_200_and_4_kpi_payload(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/members/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);

        // 4 KPI keys present.
        self::assertArrayHasKey('total_members', $body['data']);
        self::assertArrayHasKey('new_members',   $body['data']);
        self::assertArrayHasKey('supporters',    $body['data']);
        self::assertArrayHasKey('inactive',      $body['data']);

        // Each KPI has {value, sparkline} shape.
        foreach (['total_members', 'new_members', 'supporters', 'inactive'] as $key) {
            self::assertArrayHasKey('value',     $body['data'][$key], "$key.value missing");
            self::assertArrayHasKey('sparkline', $body['data'][$key], "$key.sparkline missing");
            self::assertIsInt($body['data'][$key]['value'], "$key.value should be int");
            self::assertIsArray($body['data'][$key]['sparkline'], "$key.sparkline should be array");
        }

        // Inactive sparkline comes from audit repo: 30 zero-filled entries (no audit rows seeded).
        self::assertCount(30, $body['data']['inactive']['sparkline']);
        self::assertSame(0, $body['data']['inactive']['value']);
    }

    public function test_non_admin_gets_403(): void
    {
        $member      = $this->h->seedUser('member-membersstats@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/members/stats', $memberToken);

        self::assertSame(403, $resp->status());
    }
}
