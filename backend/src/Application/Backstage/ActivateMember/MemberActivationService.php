<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ActivateMember;

use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class MemberActivationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserTenantRepositoryInterface $userTenants,
        private readonly TenantMemberCounterRepositoryInterface $counters,
        private readonly MemberStatusAuditRepositoryInterface $audit,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {}

    /**
     * @param array{name: string, email: string, date_of_birth: ?string, country: ?string} $applicationFields
     * @return array{userId: string, memberNumber: string}
     */
    public function execute(
        string $tenantId,
        string $performingAdminId,
        array $applicationFields,
    ): array {
        $now          = $this->clock->now();
        $userId       = $this->ids->generate();
        $memberNumber = $this->counters->allocateNext($tenantId);

        $this->users->createActivated($userId, [
            'name'              => $applicationFields['name'],
            'email'             => $applicationFields['email'],
            'date_of_birth'     => $applicationFields['date_of_birth'] ?? null,
            'country'           => $applicationFields['country'] ?? '',
            'membership_type'   => MembershipType::Basic->value,
            'membership_status' => 'active',
            'member_number'     => $memberNumber,
        ], $now);

        $this->userTenants->attach(
            UserId::fromString($userId),
            TenantId::fromString($tenantId),
            UserTenantRole::Member,
        );

        $this->audit->save(new MemberStatusAudit(
            $this->ids->generate(),
            $tenantId,
            $userId,
            null,
            'active',
            'application_approved',
            $performingAdminId,
            $now,
        ));

        return ['userId' => $userId, 'memberNumber' => $memberNumber];
    }
}
