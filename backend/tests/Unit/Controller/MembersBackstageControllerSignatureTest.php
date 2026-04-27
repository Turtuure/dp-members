<?php

declare(strict_types=1);

namespace DaemsModule\Members\Tests\Unit\Controller;

use DaemsModule\Members\Controller\MembersBackstageController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MembersBackstageControllerSignatureTest extends TestCase
{
    private const EXPECTED_METHODS = [
        'pendingApplications',
        'decidedApplications',
        'applicationDetail',
        'decideApplication',
        'dismissApplication',
        'members',
        'changeMemberStatus',
        'memberAudit',
        'statsMembers',
        'statsApplications',
        'listPendingForAdmin',
    ];

    public function test_class_exists(): void
    {
        self::assertTrue(class_exists(MembersBackstageController::class));
    }

    public function test_has_all_expected_public_methods(): void
    {
        $rc = new ReflectionClass(MembersBackstageController::class);
        $publicMethodNames = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $rc->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        $publicMethodNames = array_filter(
            $publicMethodNames,
            static fn (string $n): bool => $n !== '__construct'
        );

        sort($publicMethodNames);
        $expected = self::EXPECTED_METHODS;
        sort($expected);

        self::assertSame($expected, array_values($publicMethodNames));
    }
}
