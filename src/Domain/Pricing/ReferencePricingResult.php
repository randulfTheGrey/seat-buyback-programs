<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;

final readonly class ReferencePricingResult
{
    /** @var array<string, ReferencePriceOutcome> */
    private array $outcomes;

    /** @param iterable<ReferencePriceOutcome> $outcomes */
    public function __construct(iterable $outcomes)
    {
        $indexed = [];

        foreach ($outcomes as $outcome) {
            $indexed[self::key($outcome->typeId, $outcome->logicalMode)] = $outcome;
        }

        ksort($indexed);
        $this->outcomes = $indexed;
    }

    /** @return list<ReferencePriceOutcome> */
    public function all(): array
    {
        return array_values($this->outcomes);
    }

    public function outcome(int $typeId, ReferenceMode $mode): ?ReferencePriceOutcome
    {
        return $this->outcomes[self::key($typeId, $mode)] ?? null;
    }

    private static function key(int $typeId, ReferenceMode $mode): string
    {
        return $mode->value . ':' . $typeId;
    }
}
