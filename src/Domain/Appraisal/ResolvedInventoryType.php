<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

use InvalidArgumentException;

final readonly class ResolvedInventoryType
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public int $groupId,
    ) {
        if ($typeId <= 0 || $groupId <= 0 || $typeName === '') {
            throw new InvalidArgumentException('Resolved inventory types require positive IDs and a name.');
        }
    }
}
