<?php
declare(strict_types=1);

namespace DaemsModule\Members\Controller;

use DaemsModule\Members\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use DaemsModule\Members\Application\Member\GetPublicMemberProfile\GetPublicMemberProfileInput;
use Daems\Domain\Shared\NotFoundException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class MemberController
{
    public function __construct(
        private readonly GetPublicMemberProfile $getPublicMemberProfile,
    ) {}

    /** @param array<string, string> $params */
    public function getPublicProfile(Request $request, array $params): Response
    {
        $id = (string) ($params['id'] ?? '');
        if ($id === '') {
            return Response::json(['error' => 'invalid_member_id'], 400);
        }

        try {
            $out = $this->getPublicMemberProfile->execute(new GetPublicMemberProfileInput($id));
        } catch (NotFoundException) {
            return Response::json(['error' => 'member_not_found'], 404);
        }

        return Response::json(['data' => $out->toArray()]);
    }
}
