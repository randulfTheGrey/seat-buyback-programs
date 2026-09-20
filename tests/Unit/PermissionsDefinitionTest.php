<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PermissionsDefinitionTest extends TestCase
{
    public function test_permissions_have_the_approved_fully_qualified_names(): void
    {
        $definitions = require dirname(__DIR__, 2) . '/config/permissions.php';
        $names = array_map(
            static fn (string $permission): string => 'randulfthegrey-buyback.' . $permission,
            array_keys($definitions),
        );

        self::assertSame([
            'randulfthegrey-buyback.request',
            'randulfthegrey-buyback.manage',
            'randulfthegrey-buyback.admin',
        ], $names);
    }
}
