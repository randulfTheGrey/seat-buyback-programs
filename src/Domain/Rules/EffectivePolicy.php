<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final readonly class EffectivePolicy
{
    /**
     * @param list<AppliedPolicyLayer> $layers
     */
    public function __construct(
        public ProgramPolicyDefaults $baseline,
        public Acceptance $acceptance,
        public ReferenceMode $referenceMode,
        public BasisPoints $effectiveModifierBps,
        public array $layers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'baseline' => $this->baseline->toExplanationArray(),
            'acceptance' => $this->acceptance->value,
            'reference_mode' => $this->referenceMode->value,
            'effective_modifier_bps' => $this->effectiveModifierBps->value,
            'layers' => array_map(
                static fn (AppliedPolicyLayer $layer): array => $layer->toArray(),
                $this->layers,
            ),
        ];
    }
}
