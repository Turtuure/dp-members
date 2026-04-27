<?php

declare(strict_types=1);

namespace DaemsModule\Members\Application\Backstage\DecideApplication;

use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use Daems\Application\Invite\IssueInvite\IssueInvite;
use Daems\Application\Invite\IssueInvite\IssueInviteInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use DateTimeImmutable;

final class DecideApplication
{
    public function __construct(
        private readonly MemberApplicationRepositoryInterface $memberApps,
        private readonly SupporterApplicationRepositoryInterface $supporterApps,
        private readonly MemberActivationService $memberActivation,
        private readonly SupporterActivationService $supporterActivation,
        private readonly IssueInvite $issueInvite,
        private readonly AdminApplicationDismissalRepositoryInterface $dismissals,
        private readonly TransactionManagerInterface $tx,
        private readonly Clock $clock,
    ) {}

    public function execute(DecideApplicationInput $input): DecideApplicationOutput
    {
        $tenantId = $input->acting->activeTenant;

        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        if (!in_array($input->decision, ['approved', 'rejected'], true)) {
            throw new ValidationException(['decision' => 'invalid_value']);
        }

        if (!in_array($input->type, ['member', 'supporter'], true)) {
            throw new ValidationException(['type' => 'invalid_value']);
        }

        $now = $this->clock->now();

        return $this->tx->run(function () use ($input, $tenantId, $now): DecideApplicationOutput {
            if ($input->type === 'member') {
                return $this->handleMember($input, $tenantId, $now);
            }
            return $this->handleSupporter($input, $tenantId, $now);
        });
    }

    private function handleMember(
        DecideApplicationInput $input,
        TenantId $tenantId,
        DateTimeImmutable $now,
    ): DecideApplicationOutput {
        $app = $this->memberApps->findByIdForTenant($input->id, $tenantId)
            ?? throw new NotFoundException('application_not_found');

        if ($app->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        if ($input->decision === 'rejected') {
            $this->memberApps->recordDecision($input->id, $tenantId, 'rejected', $input->acting->id, $input->note, $now);
            $this->dismissals->deleteByAppId($input->id);
            return new DecideApplicationOutput(true);
        }

        $activation = $this->memberActivation->execute(
            tenantId: $tenantId->value(),
            performingAdminId: $input->acting->id->value(),
            applicationFields: [
                'name'          => $app->name(),
                'email'         => $app->email(),
                'date_of_birth' => $app->dateOfBirth(),
                'country'       => $app->country(),
            ],
        );

        $invite = $this->issueInvite->execute(new IssueInviteInput($activation['userId'], $tenantId->value()));

        $this->memberApps->recordDecision($input->id, $tenantId, 'approved', $input->acting->id, $input->note, $now);
        $this->dismissals->deleteByAppId($input->id);

        return new DecideApplicationOutput(
            success: true,
            activatedUserId: $activation['userId'],
            memberNumber: $activation['memberNumber'],
            inviteUrl: $invite->inviteUrl,
            inviteExpiresAt: $invite->expiresAt->format('Y-m-d H:i:s'),
        );
    }

    private function handleSupporter(
        DecideApplicationInput $input,
        TenantId $tenantId,
        DateTimeImmutable $now,
    ): DecideApplicationOutput {
        $app = $this->supporterApps->findByIdForTenant($input->id, $tenantId)
            ?? throw new NotFoundException('application_not_found');

        if ($app->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        if ($input->decision === 'rejected') {
            $this->supporterApps->recordDecision($input->id, $tenantId, 'rejected', $input->acting->id, $input->note, $now);
            $this->dismissals->deleteByAppId($input->id);
            return new DecideApplicationOutput(true);
        }

        $activation = $this->supporterActivation->execute(
            tenantId: $tenantId->value(),
            applicationFields: [
                'name'    => $app->contactPerson(),
                'email'   => $app->email(),
                'country' => $app->country(),
            ],
        );

        $invite = $this->issueInvite->execute(new IssueInviteInput($activation['userId'], $tenantId->value()));

        $this->supporterApps->recordDecision($input->id, $tenantId, 'approved', $input->acting->id, $input->note, $now);
        $this->dismissals->deleteByAppId($input->id);

        return new DecideApplicationOutput(
            success: true,
            activatedUserId: $activation['userId'],
            supporterNumber: $activation['supporterNumber'],
            inviteUrl: $invite->inviteUrl,
            inviteExpiresAt: $invite->expiresAt->format('Y-m-d H:i:s'),
        );
    }
}
