<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

final readonly class InventoryParseResult
{
    /**
     * @param list<ParsedInventoryLine> $parsedLines
     * @param list<InvalidInventoryLine> $invalidLines
     */
    public function __construct(
        public array $parsedLines,
        public array $invalidLines,
        public int $nonBlankLineCount,
    ) {
    }
}
