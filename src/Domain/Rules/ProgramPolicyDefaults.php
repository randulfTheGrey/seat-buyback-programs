<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\PolicyLayerSource;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final readonly class ProgramPolicyDefaults
{
    public function __construct(
        public Acceptance $acceptance,
        public ReferenceMode $referenceMode,
        public BasisPoints $modifierBps,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toExplanationArray(): array
    {
        return [
            'source' => PolicyLayerSource::PROGRAM_DEFAULTS->value,
            'semantic_layer' => 'GLOBAL',
            'rule_id' => null,
            'match_reason' => 'Program defaults are the sole GLOBAL policy baseline.',
            'acceptance' => [
                'operation' => 'DEFAULT',
                'value' => $this->acceptance->value,
            ],
            'reference_mode' => [
                'operation' => 'DEFAULT',
                'value' => $this->referenceMode->value,
            ],
            'modifier' => [
                'operation' => 'DEFAULT',
                'value_bps' => $this->modifierBps->value,
            ],
        ];
    }
}
