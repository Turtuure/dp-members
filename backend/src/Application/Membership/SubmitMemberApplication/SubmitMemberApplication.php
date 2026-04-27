<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Membership\SubmitMemberApplication;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;

final class SubmitMemberApplication
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $applications,
    ) {}

    public function execute(SubmitMemberApplicationInput $input): SubmitMemberApplicationOutput
    {
        $application = new MemberApplication(
            MemberApplicationId::generate(),
            $input->tenantId,
            $input->name,
            $input->email,
            $input->dateOfBirth,
            $input->country,
            $input->motivation,
            $input->howHeard,
            'pending',
        );

        $this->applications->save($application);

        return new SubmitMemberApplicationOutput($application->id()->value());
    }
}
