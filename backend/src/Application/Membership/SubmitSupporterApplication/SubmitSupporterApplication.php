<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Membership\SubmitSupporterApplication;

use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationId;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;

final class SubmitSupporterApplication
{
    public function __construct(
        private readonly SupporterApplicationRepositoryInterface $applications,
    ) {}

    public function execute(SubmitSupporterApplicationInput $input): SubmitSupporterApplicationOutput
    {
        $application = new SupporterApplication(
            SupporterApplicationId::generate(),
            $input->tenantId,
            $input->orgName,
            $input->contactPerson,
            $input->regNo,
            $input->email,
            $input->country,
            $input->motivation,
            $input->howHeard,
            'pending',
        );

        $this->applications->save($application);

        return new SubmitSupporterApplicationOutput($application->id()->value());
    }
}
