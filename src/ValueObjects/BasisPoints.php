<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\ValueObjects;

use InvalidArgumentException;

final readonly class BasisPoints
{
    public const MIN = -10000;

    public const MAX = 10000;

    public function __construct(public int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidArgumentException(sprintf(
                'Basis points must be between %d and %d; %d given.',
                self::MIN,
                self::MAX,
                $value,
            ));
        }
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
