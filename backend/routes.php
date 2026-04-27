<?php

declare(strict_types=1);

use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use DaemsModule\Members\Controller\ApplicationController;
use DaemsModule\Members\Controller\MemberController;
use DaemsModule\Members\Controller\MembersBackstageController;

/**
 * Members module — route registrations.
 *
 * 2 public application + 1 public member profile + 11 backstage = 14 routes.
 * Middleware lists match core daems-platform/routes/api.php exactly. The public
 * /api/v1/members/{id} endpoint intentionally has NO middleware (no tenant /
 * auth gate) — it is the verifier-card lookup hit by anyone scanning a QR.
 *
 * Module routes are registered AFTER core routes via ModuleRegistry::registerRoutes.
 * Note: the Router stores routes in append order and dispatches FIRST-MATCH, so
 * during Wave C both core and module entries coexist and CORE wins. Wave E task 22
 * deletes the duplicate core entries from daems-platform/routes/api.php — at that
 * point this module's routes become the only registrations and the dispatcher
 * routes traffic to DaemsModule\Members\Controller\*.
 */
return static function (Router $router, Container $container): void {
    // ---------------------------------------------------------------------
    // Public — application submission (2 routes)
    // ---------------------------------------------------------------------
    $router->post('/api/v1/applications/member', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->member($req);
    }, [TenantContextMiddleware::class]);

    $router->post('/api/v1/applications/supporter', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->supporter($req);
    }, [TenantContextMiddleware::class]);

    // ---------------------------------------------------------------------
    // Public — member verification card (NO auth, NO tenant). {id} = users.id (UUIDv7).
    // ---------------------------------------------------------------------
    $router->get('/api/v1/members/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(MemberController::class)->getPublicProfile($req, $params);
    }, []);

    // ---------------------------------------------------------------------
    // Backstage — applications (7 routes)
    // ---------------------------------------------------------------------
    $router->get('/api/v1/backstage/applications/pending', static function (Request $req) use ($container): Response {
        return $container->make(MembersBackstageController::class)->pendingApplications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/applications/decided', static function (Request $req) use ($container): Response {
        return $container->make(MembersBackstageController::class)->decidedApplications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/applications/pending-count', static function (Request $req) use ($container): Response {
        return $container->make(MembersBackstageController::class)->listPendingForAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/applications/stats', static function (Request $req) use ($container): Response {
        return $container->make(MembersBackstageController::class)->statsApplications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/applications/{type}/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(MembersBackstageController::class)->applicationDetail($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/applications/{type}/{id}/decision', static function (Request $req, array $params) use ($container): Response {
        return $container->make(MembersBackstageController::class)->decideApplication($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/applications/{type}/{id}/dismiss', static function (Request $req, array $params) use ($container): Response {
        return $container->make(MembersBackstageController::class)->dismissApplication($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // ---------------------------------------------------------------------
    // Backstage — members directory (4 routes)
    // ---------------------------------------------------------------------
    $router->get('/api/v1/backstage/members', static function (Request $req) use ($container): Response {
        return $container->make(MembersBackstageController::class)->members($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/members/stats', static function (Request $req) use ($container): Response {
        return $container->make(MembersBackstageController::class)->statsMembers($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/members/{id}/status', static function (Request $req, array $params) use ($container): Response {
        return $container->make(MembersBackstageController::class)->changeMemberStatus($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/members/{id}/audit', static function (Request $req, array $params) use ($container): Response {
        return $container->make(MembersBackstageController::class)->memberAudit($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);
};
