<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Integration;

use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\User\UserId;
use DaemsModule\Members\Infrastructure\SqlAdminApplicationDismissalRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class AdminApplicationDismissalSliceTest extends MigrationTestCase
{
    private SqlAdminApplicationDismissalRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(61);
        $this->repo = new SqlAdminApplicationDismissalRepository($this->pdo);
    }

    public function test_dismissed_app_ids_for_admin_returns_only_their_own_dismissals(): void
    {
        $adminA = $this->seedAdmin('admin-a');
        $adminB = $this->seedAdmin('admin-b');

        $appA1 = Uuid7::generate()->value();
        $appA2 = Uuid7::generate()->value();
        $appB1 = Uuid7::generate()->value();

        // 2 dismissals for adminA
        $this->insertDismissal($adminA->value(), $appA1, 'member');
        $this->insertDismissal($adminA->value(), $appA2, 'supporter');

        // 1 dismissal for adminB (different app_id, otherwise UNIQUE would block)
        $this->insertDismissal($adminB->value(), $appB1, 'member');

        $a = $this->repo->dismissedAppIdsFor($adminA);
        $b = $this->repo->dismissedAppIdsFor($adminB);

        self::assertCount(2, $a);
        self::assertEqualsCanonicalizing([$appA1, $appA2], $a);

        self::assertCount(1, $b);
        self::assertSame([$appB1], $b);
    }

    public function test_returns_empty_when_admin_has_no_dismissals(): void
    {
        $admin = $this->seedAdmin('lonely-admin');

        $result = $this->repo->dismissedAppIdsFor($admin);

        self::assertSame([], $result);
    }

    /**
     * Seed a minimal users row (FK target for admin_application_dismissals.admin_id)
     * and return its UserId.
     */
    private function seedAdmin(string $label): UserId
    {
        $id = Uuid7::generate()->value();
        $this->pdo->prepare(
            'INSERT INTO users (id, name, email, password_hash, date_of_birth, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $id,
            $label,
            $label . '-' . substr(str_replace('-', '', $id), 0, 8) . '@example.test',
            password_hash('x', PASSWORD_BCRYPT),
            '1990-01-01',
        ]);

        return UserId::fromString($id);
    }

    private function insertDismissal(string $adminId, string $appId, string $appType): void
    {
        $this->pdo->prepare(
            'INSERT INTO admin_application_dismissals
                (id, admin_id, app_id, app_type, dismissed_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([
            Uuid7::generate()->value(),
            $adminId,
            $appId,
            $appType,
        ]);
    }
}
