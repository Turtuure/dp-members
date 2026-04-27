<?php
declare(strict_types=1);

namespace DaemsModule\Members\Application\Member\GetPublicMemberProfile;

use Daems\Domain\Member\PublicMemberProfile;

final class GetPublicMemberProfileOutput
{
    public function __construct(
        public readonly string $memberNumberRaw,
        public readonly string $name,
        public readonly string $memberType,
        public readonly ?string $role,
        public readonly ?string $joinedAt,
        public readonly string $tenantSlug,
        public readonly string $tenantName,
        public readonly ?string $tenantMemberNumberPrefix,
        public readonly bool $publicAvatarVisible,
        public readonly string $avatarInitials,
        public readonly ?string $avatarUrl,
    ) {}

    public static function fromProfile(PublicMemberProfile $p): self
    {
        return new self(
            $p->memberNumberRaw, $p->name, $p->memberType, $p->role, $p->joinedAt,
            $p->tenantSlug, $p->tenantName, $p->tenantMemberNumberPrefix,
            $p->publicAvatarVisible, $p->avatarInitials, $p->avatarUrl,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'member_number_raw'           => $this->memberNumberRaw,
            'name'                        => $this->name,
            'member_type'                 => $this->memberType,
            'role'                        => $this->role,
            'joined_at'                   => $this->joinedAt,
            'tenant_slug'                 => $this->tenantSlug,
            'tenant_name'                 => $this->tenantName,
            'tenant_member_number_prefix' => $this->tenantMemberNumberPrefix,
            'public_avatar_visible'       => $this->publicAvatarVisible,
            'avatar_initials'             => $this->avatarInitials,
            'avatar_url'                  => $this->avatarUrl,
        ];
    }
}
