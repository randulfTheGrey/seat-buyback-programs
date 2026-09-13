<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules;

use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;

final readonly class ItemPolicyContext
{
    public function __construct(
        public int $typeId,
        public int $groupId,
        public CompressionState $compressionState,
    ) {
        if ($typeId <= 0) {
            throw new InvalidArgumentException('Item type ID must be a positive integer.');
        }

        if ($groupId <= 0) {
            throw new InvalidArgumentException('Item group ID must be a positive integer.');
        }
    }
}
