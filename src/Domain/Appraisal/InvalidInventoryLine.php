<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\InventoryInputError;

final readonly class InvalidInventoryLine
{
    public function __construct(
        public int $lineNumber,
        public string $rawLine,
        public InventoryInputError $error,
        public ?string $candidateName = null,
    ) {
    }
}
