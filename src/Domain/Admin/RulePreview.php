<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\EffectivePolicy;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;

final readonly class RulePreview
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public int $groupId,
        public string $groupName,
        public CompressionState $compression,
        public EffectivePolicy $policy,
    ) {
    }
}
