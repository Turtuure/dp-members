<?php
declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Application\Member;

use DaemsModule\Members\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use DaemsModule\Members\Application\Member\GetPublicMemberProfile\GetPublicMemberProfileInput;
use Daems\Domain\Member\PublicMemberProfile;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use PHPUnit\Framework\TestCase;

final class GetPublicMemberProfileTest extends TestCase
{
    private const USER_ID_A = '019d9f72-436e-7df6-9c33-ad9545952a8a';
    private const USER_ID_B = '019d9f72-436e-7df6-9c33-ad9545952a8b';
    private const USER_ID_UNKNOWN = '019d9f72-436e-7df6-9c33-ad9545952aff';

    public function test_returns_profile_for_known_user_id(): void
    {
        $repo = $this->fakeRepoWith(self::USER_ID_A, '123', 'Sam', 'DAEMS', true, '/avatars/sam.webp');
        $uc = new GetPublicMemberProfile($repo);
        $out = $uc->execute(new GetPublicMemberProfileInput(self::USER_ID_A));

        self::assertSame('Sam', $out->name);
        self::assertSame('DAEMS', $out->tenantMemberNumberPrefix);
        self::assertSame('/avatars/sam.webp', $out->avatarUrl);
        self::assertTrue($out->publicAvatarVisible);
    }

    public function test_returns_null_avatar_when_visibility_off(): void
    {
        $repo = $this->fakeRepoWith(self::USER_ID_B, '456', 'Juma', null, false, null);
        $uc = new GetPublicMemberProfile($repo);
        $out = $uc->execute(new GetPublicMemberProfileInput(self::USER_ID_B));

        self::assertFalse($out->publicAvatarVisible);
        self::assertNull($out->avatarUrl);
    }

    public function test_throws_not_found_for_unknown_user_id(): void
    {
        $repo = new class implements PublicMemberRepositoryInterface {
            public function findByUserId(string $id): ?PublicMemberProfile { return null; }
        };
        $uc = new GetPublicMemberProfile($repo);
        $this->expectException(NotFoundException::class);
        $uc->execute(new GetPublicMemberProfileInput(self::USER_ID_UNKNOWN));
    }

    private function fakeRepoWith(string $userId, string $memberNumber, string $name, ?string $prefix, bool $avatarVisible, ?string $avatarUrl): PublicMemberRepositoryInterface
    {
        $profile = new PublicMemberProfile(
            memberNumberRaw: $memberNumber,
            name: $name,
            memberType: 'basic',
            role: 'member',
            joinedAt: '2024-06-11',
            tenantSlug: 'daems',
            tenantName: 'Daems',
            tenantMemberNumberPrefix: $prefix,
            publicAvatarVisible: $avatarVisible,
            avatarInitials: strtoupper(substr($name, 0, 2)),
            avatarUrl: $avatarUrl,
        );
        return new class ($userId, $profile) implements PublicMemberRepositoryInterface {
            public function __construct(
                private readonly string $expectedId,
                private readonly PublicMemberProfile $p,
            ) {}
            public function findByUserId(string $id): ?PublicMemberProfile {
                return $id === $this->expectedId ? $this->p : null;
            }
        };
    }
}
