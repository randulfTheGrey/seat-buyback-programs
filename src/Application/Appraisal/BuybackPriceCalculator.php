<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Appraisal;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\CalculatedBuybackPrice;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final class BuybackPriceCalculator
{
    public function calculate(
        BigDecimal $referenceUnitPrice,
        BasisPoints $modifierBps,
        int $quantity,
    ): CalculatedBuybackPrice {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Appraisal quantity must be positive.');
        }

        $rawFinalUnitPrice = $referenceUnitPrice
            ->multipliedBy(10000 + $modifierBps->value)
            ->exactlyDividedBy(BigDecimal::of(10000));
        $finalUnitPrice = $rawFinalUnitPrice->toScale(2, RoundingMode::HALF_UP);
        $lineTotal = $finalUnitPrice->multipliedBy($quantity)->toScale(2);

        return new CalculatedBuybackPrice($rawFinalUnitPrice, $finalUnitPrice, $lineTotal);
    }
}
