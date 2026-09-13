<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\PolicyLayerSource;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final readonly class AppliedPolicyLayer
{
    public function __construct(
        public PolicyLayerSource $source,
        public PolicyRule $rule,
        public string $matchReason,
        public RuleAcceptance $acceptanceOperation,
        public Acceptance $acceptanceBefore,
        public Acceptance $acceptanceAfter,
        public ?ReferenceMode $referenceModeOverride,
        public ReferenceMode $referenceModeBefore,
        public ReferenceMode $referenceModeAfter,
        public ModifierOperation $modifierOperation,
        public ?BasisPoints $modifierOperandBps,
        public BasisPoints $modifierBeforeBps,
        public BasisPoints $modifierAfterBps,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'rule_id' => $this->rule->ruleId,
            'target_type' => $this->rule->targetType->value,
            'target_id' => $this->rule->targetId,
            'compression_qualifier' => $this->rule->compressionQualifier->value,
            'match_reason' => $this->matchReason,
            'acceptance' => [
                'operation' => $this->acceptanceOperation->value,
                'before' => $this->acceptanceBefore->value,
                'after' => $this->acceptanceAfter->value,
                'applied' => $this->acceptanceOperation !== RuleAcceptance::INHERIT,
            ],
            'reference_mode' => [
                'operation' => $this->referenceModeOverride === null ? 'INHERIT' : 'OVERRIDE',
                'value' => $this->referenceModeOverride?->value,
                'before' => $this->referenceModeBefore->value,
                'after' => $this->referenceModeAfter->value,
                'applied' => $this->referenceModeOverride !== null,
            ],
            'modifier' => [
                'operation' => $this->modifierOperation->value,
                'value_bps' => $this->modifierOperandBps?->value,
                'before_bps' => $this->modifierBeforeBps->value,
                'after_bps' => $this->modifierAfterBps->value,
                'applied' => $this->modifierOperation !== ModifierOperation::INHERIT,
            ],
        ];
    }
}
