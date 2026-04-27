<?php
declare(strict_types=1);

namespace DaemsModule\Members\Application\Member\GetPublicMemberProfile;

use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;

final class GetPublicMemberProfile
{
    public function __construct(private readonly PublicMemberRepositoryInterface $repo) {}

    public function execute(GetPublicMemberProfileInput $input): GetPublicMemberProfileOutput
    {
        $profile = $this->repo->findByUserId($input->userId)
            ?? throw new NotFoundException('member_not_found');
        return GetPublicMemberProfileOutput::fromProfile($profile);
    }
}
