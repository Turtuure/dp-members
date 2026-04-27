<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Backstage;

use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplication;
use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplicationInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use DaemsModule\Members\Tests\Support\InMemoryAdminApplicationDismissalRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DismissApplicationTest extends TestCase
{
    private TenantId $tenant;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    private function makeClock(): \Daems\Domain\Shared\Clock
    {
        return new class implements \Daems\Domain\Shared\Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-04-20 12:00:00');
            }
        };
    }

    private function makeIds(string $id = 'd-1'): \Daems\Domain\Shared\IdGeneratorInterface
    {
        return new class($id) implements \Daems\Domain\Shared\IdGeneratorInterface {
            public function __construct(private readonly string $id) {}
            public function generate(): string { return $this->id; }
        };
    }

    private function adminActingUser(string $userId = '01958000-0000-7000-8000-000000000010'): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString($userId),
            email: 'admin@x.com',
            isPlatformAdmin: false,
            activeTenant: $this->tenant,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function nonAdminActingUser(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString('01958000-0000-7000-8000-000000000011'),
            email: 'member@x.com',
            isPlatformAdmin: false,
            activeTenant: $this->tenant,
            roleInActiveTenant: UserTenantRole::Member,
        );
    }

    public function test_dismiss_stores_row_keyed_by_admin_and_app(): void
    {
        $repo   = new InMemoryAdminApplicationDismissalRepository();
        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');

        $sut = new DismissApplication($repo, $this->makeClock(), $this->makeIds());
        $sut->execute(new DismissApplicationInput($acting, '01958000-0000-7000-8000-000000000020', 'member'));

        self::assertSame(
            ['01958000-0000-7000-8000-000000000020'],
            $repo->listAppIdsDismissedByAdmin('01958000-0000-7000-8000-000000000010'),
        );
    }

    public function test_dismiss_is_idempotent(): void
    {
        $repo   = new InMemoryAdminApplicationDismissalRepository();
        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');

        $sut = new DismissApplication($repo, $this->makeClock(), $this->makeIds());
        $sut->execute(new DismissApplicationInput($acting, '01958000-0000-7000-8000-000000000020', 'member'));
        $sut->execute(new DismissApplicationInput($acting, '01958000-0000-7000-8000-000000000020', 'member'));

        self::assertSame(
            ['01958000-0000-7000-8000-000000000020'],
            $repo->listAppIdsDismissedByAdmin('01958000-0000-7000-8000-000000000010'),
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);

        $repo   = new InMemoryAdminApplicationDismissalRepository();
        $acting = $this->nonAdminActingUser();

        $sut = new DismissApplication($repo, $this->makeClock(), $this->makeIds());
        $sut->execute(new DismissApplicationInput($acting, '01958000-0000-7000-8000-000000000020', 'member'));
    }

    public function test_rejects_invalid_app_type(): void
    {
        $this->expectException(ValidationException::class);

        $repo   = new InMemoryAdminApplicationDismissalRepository();
        $acting = $this->adminActingUser();

        $sut = new DismissApplication($repo, $this->makeClock(), $this->makeIds());
        $sut->execute(new DismissApplicationInput($acting, '01958000-0000-7000-8000-000000000020', 'invalid'));
    }

    public function test_accepts_project_proposal_app_type(): void
    {
        $repo   = new InMemoryAdminApplicationDismissalRepository();
        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');

        $sut = new DismissApplication($repo, $this->makeClock(), $this->makeIds());
        $sut->execute(new DismissApplicationInput(
            $acting,
            '01959900-0000-7000-8000-0000000000aa',
            'project_proposal',
        ));

        self::assertSame(
            ['01959900-0000-7000-8000-0000000000aa'],
            $repo->listAppIdsDismissedByAdmin('01958000-0000-7000-8000-000000000010'),
        );
    }

    public function test_accepts_forum_report_type_with_compound_id(): void
    {
        $repo   = new InMemoryAdminApplicationDismissalRepository();
        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');

        $compoundPost  = 'post:01958000-0000-7000-8000-0000000a0001';
        $compoundTopic = 'topic:01958000-0000-7000-8000-000000010001';

        $sut = new DismissApplication($repo, $this->makeClock(), $this->makeIds());
        $sut->execute(new DismissApplicationInput($acting, $compoundPost, 'forum_report'));
        $sut->execute(new DismissApplicationInput($acting, $compoundTopic, 'forum_report'));

        self::assertSame(
            [$compoundPost, $compoundTopic],
            $repo->listAppIdsDismissedByAdmin('01958000-0000-7000-8000-000000000010'),
        );
    }

    public function test_rejects_malformed_forum_report_compound_id(): void
    {
        $repo   = new InMemoryAdminApplicationDismissalRepository();
        $acting = $this->adminActingUser('01958000-0000-7000-8000-000000000010');

        $sut = new DismissApplication($repo, $this->makeClock(), $this->makeIds());

        $invalidIds = [
            // wrong prefix
            'user:01958000-0000-7000-8000-0000000a0001',
            // missing uuid portion
            'post:',
            // no prefix / not compound
            '01958000-0000-7000-8000-0000000a0001',
            // uppercase hex disallowed
            'post:01958000-0000-7000-8000-0000000A0001',
            // wrong separator
            'post-01958000-0000-7000-8000-0000000a0001',
        ];

        foreach ($invalidIds as $bad) {
            $caught = null;
            try {
                $sut->execute(new DismissApplicationInput($acting, $bad, 'forum_report'));
            } catch (ValidationException $e) {
                $caught = $e;
            }
            self::assertNotNull($caught, "expected ValidationException for malformed compound id: {$bad}");
        }

        self::assertSame([], $repo->listAppIdsDismissedByAdmin('01958000-0000-7000-8000-000000000010'));
    }
}
