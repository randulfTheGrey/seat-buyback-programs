<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;

final readonly class ReferencePriceRequirement
{
    public function __construct(
        public int $typeId,
        public ReferenceMode $mode,
    ) {
        if ($typeId <= 0) {
            throw new InvalidArgumentException('A reference-price type ID must be positive.');
        }
    }

    public function key(): string
    {
        return $this->mode->value . ':' . $this->typeId;
    }
}
