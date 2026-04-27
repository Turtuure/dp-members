<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\ListDecidedApplications;

final class ListDecidedApplicationsOutput
{
    /**
     * @param list<array{
     *   id: string,
     *   name: string,
     *   email: string,
     *   date_of_birth: string,
     *   country: ?string,
     *   motivation: string,
     *   decided_at: string,
     *   decision_note: ?string
     * }> $member
     * @param list<array{
     *   id: string,
     *   org_name: string,
     *   contact_person: string,
     *   email: string,
     *   country: ?string,
     *   motivation: string,
     *   decided_at: string,
     *   decision_note: ?string
     * }> $supporter
     * @param 'approved'|'rejected' $decision
     */
    public function __construct(
        public readonly array $member,
        public readonly array $supporter,
        public readonly string $decision,
        public readonly int $days,
    ) {}

    /**
     * @return array{
     *   member: list<array{id: string, name: string, email: string, date_of_birth: string, country: ?string, motivation: string, decided_at: string, decision_note: ?string}>,
     *   supporter: list<array{id: string, org_name: string, contact_person: string, email: string, country: ?string, motivation: string, decided_at: string, decision_note: ?string}>,
     *   total: int,
     *   decision: string,
     *   days: int
     * }
     */
    public function toArray(): array
    {
        return [
            'member'    => $this->member,
            'supporter' => $this->supporter,
            'total'     => count($this->member) + count($this->supporter),
            'decision'  => $this->decision,
            'days'      => $this->days,
        ];
    }
}
