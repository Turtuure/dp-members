<?php

declare(strict_types=1);

namespace DaemsModule\Members\Controller;

use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplicationInput;
use DaemsModule\Members\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use DaemsModule\Members\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplicationInput;
use Daems\Domain\Tenant\Tenant;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class ApplicationController
{
    public function __construct(
        private readonly SubmitMemberApplication $submitMember,
        private readonly SubmitSupporterApplication $submitSupporter,
    ) {}

    public function member(Request $request): Response
    {
        $tenantId = $this->resolveTenantId($request);

        $name        = trim($request->string('name') ?? '');
        $email       = trim($request->string('email') ?? '');
        $dob         = trim($request->string('date_of_birth') ?? '');
        $country     = trim($request->string('country') ?? '') ?: null;
        $motivation  = trim($request->string('motivation') ?? '');
        $howHeard    = trim($request->string('how_heard') ?? '') ?: null;

        if ($name === '' || $email === '' || $dob === '' || $motivation === '') {
            return Response::badRequest('Name, email, date of birth and motivation are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Invalid email address.');
        }

        $output = $this->submitMember->execute(
            new SubmitMemberApplicationInput($tenantId, $name, $email, $dob, $country, $motivation, $howHeard),
        );

        return Response::json(['data' => ['id' => $output->id]], 201);
    }

    public function supporter(Request $request): Response
    {
        $tenantId = $this->resolveTenantId($request);

        $orgName       = trim($request->string('org_name') ?? '');
        $contactPerson = trim($request->string('contact_person') ?? '');
        $regNo         = trim($request->string('reg_no') ?? '') ?: null;
        $email         = trim($request->string('email') ?? '');
        $country       = trim($request->string('country') ?? '') ?: null;
        $motivation    = trim($request->string('motivation') ?? '');
        $howHeard      = trim($request->string('how_heard') ?? '') ?: null;

        if ($orgName === '' || $contactPerson === '' || $email === '' || $motivation === '') {
            return Response::badRequest('Organisation name, contact person, email and motivation are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Invalid email address.');
        }

        $output = $this->submitSupporter->execute(
            new SubmitSupporterApplicationInput($tenantId, $orgName, $contactPerson, $regNo, $email, $country, $motivation, $howHeard),
        );

        return Response::json(['data' => ['id' => $output->id]], 201);
    }

    private function resolveTenantId(Request $request): \Daems\Domain\Tenant\TenantId
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new \RuntimeException('TenantContextMiddleware must run before ApplicationController');
        }
        return $tenant->id;
    }
}
