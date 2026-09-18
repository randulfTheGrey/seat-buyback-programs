<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Contracts;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\ResolvedInventoryType;

interface InventoryTypeResolver
{
    /**
     * Resolve published SeAT SDE types by exact, case-insensitive type name.
     *
     * Returned entries remain keyed by the submitted candidate name while the
     * resolved value carries the canonical SDE identity and name.
     *
     * @param iterable<string> $candidateNames
     * @return array<string, ResolvedInventoryType>
     */
    public function resolveExact(iterable $candidateNames): array;
}
