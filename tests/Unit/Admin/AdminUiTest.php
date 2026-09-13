<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class AdminUiTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function modifierInputs(): iterable
    {
        yield 'ten percent discount' => ['discount', '10.00', -1000];
        yield 'five percent premium' => ['premium', '5', 500];
        yield 'none ignores empty percentage' => ['none', '', 0];
        yield 'exact fractional percent' => ['premium', '0.01', 1];
    }

    #[DataProvider('modifierInputs')]
    public function test_modifier_input_translates_to_signed_integer_basis_points(
        string $direction,
        string $percentage,
        int $expected,
    ): void {
        self::assertSame($expected, AdminUi::modifierBps($direction, $percentage));
    }

    public function test_modifier_input_rejects_float_like_excess_precision_and_out_of_range_values(): void
    {
        foreach (['1.001', '100.01', '1e2'] as $value) {
            try {
                AdminUi::modifierBps('discount', $value);
                self::fail('Invalid percentage was accepted: ' . $value);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
