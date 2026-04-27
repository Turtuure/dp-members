<?php
declare(strict_types=1);

namespace DaemsModule\Members\Infrastructure;

use Daems\Domain\Member\PublicMemberProfile;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlPublicMemberRepository implements PublicMemberRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function findByUserId(string $userId): ?PublicMemberProfile
    {
        $row = $this->db->queryOne(
            'SELECT u.id, u.name, u.member_number, u.membership_type,
                    u.public_avatar_visible, u.created_at,
                    t.slug AS tenant_slug, t.name AS tenant_name, t.member_number_prefix,
                    ut.role
             FROM users u
             JOIN user_tenants ut ON ut.user_id = u.id
             JOIN tenants t ON t.id = ut.tenant_id
             WHERE u.id = ? AND u.deleted_at IS NULL
             ORDER BY ut.joined_at ASC
             LIMIT 1',
            [$userId],
        );
        if ($row === null) return null;

        $name = self::strOr($row, 'name', '');
        $rowUserId = self::strOr($row, 'id', '');
        $visible = (bool) ($row['public_avatar_visible'] ?? 1);

        return new PublicMemberProfile(
            memberNumberRaw: self::strOr($row, 'member_number', ''),
            name: $name,
            memberType: self::strOr($row, 'membership_type', 'basic'),
            role: self::strOrNull($row, 'role'),
            joinedAt: ($s = self::strOrNull($row, 'created_at')) !== null ? substr($s, 0, 10) : null,
            tenantSlug: self::strOr($row, 'tenant_slug', ''),
            tenantName: self::strOr($row, 'tenant_name', ''),
            tenantMemberNumberPrefix: self::strOrNull($row, 'member_number_prefix'),
            publicAvatarVisible: $visible,
            avatarInitials: self::deriveInitials($name),
            avatarUrl: $visible ? self::avatarPublicUrl($rowUserId) : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function strOr(array $row, string $key, string $default): string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @param array<string, mixed> $row */
    private static function strOrNull(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    private static function deriveInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = isset($parts[0]) ? mb_substr($parts[0], 0, 1) : '';
        $last  = '';
        $count = count($parts);
        if ($count > 1) {
            $last = mb_substr($parts[$count - 1], 0, 1);
        }
        $out = strtoupper($first . $last);
        return $out !== '' ? $out : '?';
    }

    private static function avatarPublicUrl(string $userId): ?string
    {
        $safeId = preg_replace('/[^a-f0-9\-]/', '', $userId);
        if (!is_string($safeId) || $safeId === '') return null;
        return '/uploads/avatars/' . $safeId . '.webp';
    }
}

