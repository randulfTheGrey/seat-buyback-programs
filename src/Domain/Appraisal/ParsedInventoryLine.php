<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

final readonly class ParsedInventoryLine
{
    public function __construct(
        public int $lineNumber,
        public string $rawLine,
        public string $candidateName,
        public int $quantity,
    ) {
    }
}
