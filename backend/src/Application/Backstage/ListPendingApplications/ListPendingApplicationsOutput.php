<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListPendingApplications;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\SupporterApplication;

final class ListPendingApplicationsOutput
{
    /**
     * @param list<MemberApplication>   $member
     * @param list<SupporterApplication> $supporter
     */
    public function __construct(
        public readonly array $member,
        public readonly array $supporter,
    ) {}

    /**
     * @return array{
     *   member: list<array<string, string|null>>,
     *   supporter: list<array<string, string|null>>,
     *   total: int
     * }
     */
    public function toArray(): array
    {
        $memberItems = array_map(static fn (MemberApplication $a): array => [
            'id'            => $a->id()->value(),
            'name'          => $a->name(),
            'email'         => $a->email(),
            'date_of_birth' => $a->dateOfBirth(),
            'country'       => $a->country(),
            'motivation'    => $a->motivation(),
        ], $this->member);

        $supporterItems = array_map(static fn (SupporterApplication $a): array => [
            'id'             => $a->id()->value(),
            'org_name'       => $a->orgName(),
            'contact_person' => $a->contactPerson(),
            'email'          => $a->email(),
            'country'        => $a->country(),
            'motivation'     => $a->motivation(),
        ], $this->supporter);

        return [
            'member'    => $memberItems,
            'supporter' => $supporterItems,
            'total'     => count($memberItems) + count($supporterItems),
        ];
    }
}
