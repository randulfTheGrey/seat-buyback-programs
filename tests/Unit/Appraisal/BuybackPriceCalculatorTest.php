<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Appraisal;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Appraisal\BuybackPriceCalculator;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final class BuybackPriceCalculatorTest extends TestCase
{
    public static function exactPriceProvider(): iterable
    {
        yield 'half-up half cent' => ['1.005', 0, 1, '1.01', '1.01'];
        yield 'approved ten-percent discount' => ['10003.17', -1000, 1, '9002.85', '9002.85'];
        yield 'premium' => ['10.003', 500, 2, '10.50', '21.00'];
        yield 'zero adjustment and more-than-two reference decimals' => ['9.9999', 0, 3, '10.00', '30.00'];
        yield 'zero price is valid' => ['0', 10000, 50, '0.00', '0.00'];
        yield 'maximum discount' => ['10', -10000, 5, '0.00', '0.00'];
        yield 'maximum premium' => ['10', 10000, 5, '20.00', '100.00'];
        yield 'low value and high volume' => ['0.006', 0, 5000000000, '0.01', '50000000.00'];
    }

    #[DataProvider('exactPriceProvider')]
    public function test_calculates_with_exact_decimal_modifier_rounding_and_rounded_unit_total(
        string $reference,
        int $modifier,
        int $quantity,
        string $expectedUnit,
        string $expectedTotal,
    ): void {
        $result = (new BuybackPriceCalculator())->calculate(
            BigDecimal::of($reference),
            new BasisPoints($modifier),
            $quantity,
        );

        self::assertSame($expectedUnit, (string) $result->finalUnitPrice);
        self::assertSame($expectedTotal, (string) $result->lineTotal);
    }
}
