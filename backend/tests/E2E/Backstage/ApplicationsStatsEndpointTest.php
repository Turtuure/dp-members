<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\E2E\Backstage;

use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class ApplicationsStatsEndpointTest extends TestCase
{
    private KernelHarness $h;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->h          = new KernelHarness(FrozenClock::at('2026-04-25T12:00:00Z'));
        $admin            = $this->h->seedUser('admin-applicationsstats@x.com', 'pass1234', 'admin');
        $this->adminToken = $this->h->tokenFor($admin);
    }

    public function test_admin_gets_200_and_4_kpi_payload(): void
    {
        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/stats', $this->adminToken);
        $body = json_decode($resp->body(), true);

        self::assertSame(200, $resp->status());
        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);

        // 4 KPI keys present.
        self::assertArrayHasKey('pending',            $body['data']);
        self::assertArrayHasKey('approved_30d',       $body['data']);
        self::assertArrayHasKey('rejected_30d',       $body['data']);
        self::assertArrayHasKey('avg_response_hours', $body['data']);

        // Each KPI has {value, sparkline} shape, value is int.
        foreach (['pending', 'approved_30d', 'rejected_30d', 'avg_response_hours'] as $key) {
            self::assertArrayHasKey('value',     $body['data'][$key], "$key.value missing");
            self::assertArrayHasKey('sparkline', $body['data'][$key], "$key.sparkline missing");
            self::assertIsInt($body['data'][$key]['value'], "$key.value should be int");
            self::assertIsArray($body['data'][$key]['sparkline'], "$key.sparkline should be array");
        }

        // Non-derived KPIs carry 30-entry zero-filled sparklines.
        self::assertCount(30, $body['data']['pending']['sparkline']);
        self::assertCount(30, $body['data']['approved_30d']['sparkline']);
        self::assertCount(30, $body['data']['rejected_30d']['sparkline']);

        // avg_response_hours.sparkline is empty by spec (computed metric, no time series).
        self::assertSame([], $body['data']['avg_response_hours']['sparkline']);
    }

    public function test_non_admin_gets_403(): void
    {
        $member      = $this->h->seedUser('member-applicationsstats@x.com', 'pass1234');
        $memberToken = $this->h->tokenFor($member);

        $resp = $this->h->authedRequest('GET', '/api/v1/backstage/applications/stats', $memberToken);

        self::assertSame(403, $resp->status());
    }
}
