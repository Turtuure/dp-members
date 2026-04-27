<?php

declare(strict_types=1);

use Daems\Domain\Backstage\MemberDirectoryRepositoryInterface;
use Daems\Domain\Dismissal\AdminApplicationDismissalRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\Forum\ForumReportRepositoryInterface;
use Daems\Domain\Member\PublicMemberRepositoryInterface;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\TransactionManagerInterface;
use Daems\Domain\Tenant\TenantMemberCounterRepositoryInterface;
use Daems\Domain\Tenant\TenantSupporterCounterRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use DaemsModule\Members\Application\Backstage\ActivateMember\MemberActivationService;
use DaemsModule\Members\Application\Backstage\ActivateSupporter\SupporterActivationService;
use DaemsModule\Members\Application\Backstage\Applications\ListApplicationsStats\ListApplicationsStats;
use DaemsModule\Members\Application\Backstage\ChangeMemberStatus\ChangeMemberStatus;
use DaemsModule\Members\Application\Backstage\DecideApplication\DecideApplication;
use DaemsModule\Members\Application\Backstage\DismissApplication\DismissApplication;
use DaemsModule\Members\Application\Backstage\GetApplicationDetail\GetApplicationDetail;
use DaemsModule\Members\Application\Backstage\GetMemberAudit\GetMemberAudit;
use DaemsModule\Members\Application\Backstage\ListDecidedApplications\ListDecidedApplications;
use DaemsModule\Members\Application\Backstage\ListMembers\ListMembers;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplications;
use DaemsModule\Members\Application\Backstage\ListPendingApplications\ListPendingApplicationsForAdmin;
use DaemsModule\Members\Application\Backstage\Members\ListMembersStats\ListMembersStats;
use DaemsModule\Members\Application\Member\GetPublicMemberProfile\GetPublicMemberProfile;
use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use DaemsModule\Members\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use DaemsModule\Members\Controller\ApplicationController;
use DaemsModule\Members\Controller\MemberController;
use DaemsModule\Members\Controller\MembersBackstageController;
use DaemsModule\Members\Infrastructure\SqlAdminApplicationDismissalRepository;
use DaemsModule\Members\Infrastructure\SqlMemberApplicationRepository;
use DaemsModule\Members\Infrastructure\SqlMemberDirectoryRepository;
use DaemsModule\Members\Infrastructure\SqlMemberStatusAuditRepository;
use DaemsModule\Members\Infrastructure\SqlPublicMemberRepository;
use DaemsModule\Members\Infrastructure\SqlSupporterApplicationRepository;
use DaemsModule\Members\Infrastructure\SqlTenantMemberCounterRepository;
use DaemsModule\Members\Infrastructure\SqlTenantSupporterCounterRepository;

/**
 * Members module — production DI bindings.
 *
 * Loaded by ModuleRegistry::registerBindings() AFTER core bootstrap/app.php,
 * so these bindings WIN over the legacy Members bindings still present there
 * (a later wave will remove the originals from core).
 *
 * Architecture: Domain stays in core. The 8 repository INTERFACES live under
 * \Daems\Domain\* and are bound here to the module's SQL implementations under
 * \DaemsModule\Members\Infrastructure\.
 *
 * Inventory: 8 repos + 14 use cases (incl. 2 activation services) + 3 controllers.
 *
 * Construction notes (verified against actual class signatures):
 *  - SqlAdminApplicationDismissalRepository / SqlTenantMemberCounterRepository /
 *    SqlTenantSupporterCounterRepository all take a raw PDO via $connection->pdo()
 *  - The other 5 SQL repos take Connection directly.
 *  - DecideApplication needs IssueInvite, which is bound by core bootstrap/app.php.
 *  - DismissApplication / DecideApplication / MemberActivationService /
 *    SupporterActivationService all need Clock + IdGeneratorInterface (core).
 *  - ListPendingApplicationsForAdmin pulls in ProjectProposal + Forum repos (core).
 */
return static function (Container $container): void {
    // ---------------------------------------------------------------------
    // 8× Repository bindings — CORE interfaces → MODULE SQL impls
    // ---------------------------------------------------------------------
    $container->singleton(
        MemberApplicationRepositoryInterface::class,
        static fn(Container $c) => new SqlMemberApplicationRepository($c->make(Connection::class)),
    );
    $container->singleton(
        SupporterApplicationRepositoryInterface::class,
        static fn(Container $c) => new SqlSupporterApplicationRepository($c->make(Connection::class)),
    );
    $container->singleton(
        MemberStatusAuditRepositoryInterface::class,
        static fn(Container $c) => new SqlMemberStatusAuditRepository($c->make(Connection::class)),
    );
    $container->singleton(
        MemberDirectoryRepositoryInterface::class,
        static fn(Container $c) => new SqlMemberDirectoryRepository($c->make(Connection::class)),
    );
    $container->singleton(
        PublicMemberRepositoryInterface::class,
        static fn(Container $c) => new SqlPublicMemberRepository($c->make(Connection::class)),
    );
    $container->singleton(
        AdminApplicationDismissalRepositoryInterface::class,
        static fn(Container $c) => new SqlAdminApplicationDismissalRepository($c->make(Connection::class)->pdo()),
    );
    $container->singleton(
        TenantMemberCounterRepositoryInterface::class,
        static fn(Container $c) => new SqlTenantMemberCounterRepository($c->make(Connection::class)->pdo()),
    );
    $container->singleton(
        TenantSupporterCounterRepositoryInterface::class,
        static fn(Container $c) => new SqlTenantSupporterCounterRepository($c->make(Connection::class)->pdo()),
    );

    // ---------------------------------------------------------------------
    // 2× Activation services
    // ---------------------------------------------------------------------
    $container->bind(
        MemberActivationService::class,
        static fn(Container $c) => new MemberActivationService(
            $c->make(UserRepositoryInterface::class),
            $c->make(UserTenantRepositoryInterface::class),
            $c->make(TenantMemberCounterRepositoryInterface::class),
            $c->make(MemberStatusAuditRepositoryInterface::class),
            $c->make(Clock::class),
            $c->make(IdGeneratorInterface::class),
        ),
    );
    $container->bind(
        SupporterActivationService::class,
        static fn(Container $c) => new SupporterActivationService(
            $c->make(UserRepositoryInterface::class),
            $c->make(UserTenantRepositoryInterface::class),
            $c->make(TenantSupporterCounterRepositoryInterface::class),
            $c->make(Clock::class),
            $c->make(IdGeneratorInterface::class),
        ),
    );

    // ---------------------------------------------------------------------
    // 14× Use cases
    // ---------------------------------------------------------------------
    $container->bind(
        SubmitMemberApplication::class,
        static fn(Container $c) => new SubmitMemberApplication(
            $c->make(MemberApplicationRepositoryInterface::class),
        ),
    );
    $container->bind(
        SubmitSupporterApplication::class,
        static fn(Container $c) => new SubmitSupporterApplication(
            $c->make(SupporterApplicationRepositoryInterface::class),
        ),
    );
    $container->bind(
        GetPublicMemberProfile::class,
        static fn(Container $c) => new GetPublicMemberProfile(
            $c->make(PublicMemberRepositoryInterface::class),
        ),
    );
    $container->bind(
        ListPendingApplications::class,
        static fn(Container $c) => new ListPendingApplications(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
        ),
    );
    $container->bind(
        ListPendingApplicationsForAdmin::class,
        static fn(Container $c) => new ListPendingApplicationsForAdmin(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
            $c->make(ProjectProposalRepositoryInterface::class),
            $c->make(ForumReportRepositoryInterface::class),
            $c->make(ForumRepositoryInterface::class),
        ),
    );
    $container->bind(
        ListDecidedApplications::class,
        static fn(Container $c) => new ListDecidedApplications(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
        ),
    );
    $container->bind(
        GetApplicationDetail::class,
        static fn(Container $c) => new GetApplicationDetail(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
        ),
    );
    $container->bind(
        DecideApplication::class,
        static fn(Container $c) => new DecideApplication(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
            $c->make(MemberActivationService::class),
            $c->make(SupporterActivationService::class),
            $c->make(\Daems\Application\Invite\IssueInvite\IssueInvite::class),
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
            $c->make(TransactionManagerInterface::class),
            $c->make(Clock::class),
        ),
    );
    $container->bind(
        DismissApplication::class,
        static fn(Container $c) => new DismissApplication(
            $c->make(AdminApplicationDismissalRepositoryInterface::class),
            $c->make(Clock::class),
            $c->make(IdGeneratorInterface::class),
        ),
    );
    $container->bind(
        ListMembers::class,
        static fn(Container $c) => new ListMembers(
            $c->make(MemberDirectoryRepositoryInterface::class),
        ),
    );
    $container->bind(
        ChangeMemberStatus::class,
        static fn(Container $c) => new ChangeMemberStatus(
            $c->make(MemberDirectoryRepositoryInterface::class),
            $c->make(\Daems\Application\User\AnonymiseAccount\AnonymiseAccount::class),
            $c->make(Clock::class),
        ),
    );
    $container->bind(
        GetMemberAudit::class,
        static fn(Container $c) => new GetMemberAudit(
            $c->make(MemberDirectoryRepositoryInterface::class),
        ),
    );
    $container->bind(
        ListMembersStats::class,
        static fn(Container $c) => new ListMembersStats(
            $c->make(UserTenantRepositoryInterface::class),
            $c->make(MemberStatusAuditRepositoryInterface::class),
        ),
    );
    $container->bind(
        ListApplicationsStats::class,
        static fn(Container $c) => new ListApplicationsStats(
            $c->make(MemberApplicationRepositoryInterface::class),
            $c->make(SupporterApplicationRepositoryInterface::class),
        ),
    );

    // ---------------------------------------------------------------------
    // 3× Controllers
    // ---------------------------------------------------------------------
    $container->bind(
        ApplicationController::class,
        static fn(Container $c) => new ApplicationController(
            $c->make(SubmitMemberApplication::class),
            $c->make(SubmitSupporterApplication::class),
        ),
    );
    $container->bind(
        MemberController::class,
        static fn(Container $c) => new MemberController(
            $c->make(GetPublicMemberProfile::class),
        ),
    );
    $container->bind(
        MembersBackstageController::class,
        static fn(Container $c) => new MembersBackstageController(
            $c->make(ListPendingApplications::class),
            $c->make(ListDecidedApplications::class),
            $c->make(GetApplicationDetail::class),
            $c->make(DecideApplication::class),
            $c->make(DismissApplication::class),
            $c->make(ListMembers::class),
            $c->make(ChangeMemberStatus::class),
            $c->make(GetMemberAudit::class),
            $c->make(ListMembersStats::class),
            $c->make(ListApplicationsStats::class),
            $c->make(ListPendingApplicationsForAdmin::class),
        ),
    );
};
