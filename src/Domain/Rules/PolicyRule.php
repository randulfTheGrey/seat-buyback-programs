<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidPolicyRuleException;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final readonly class PolicyRule
{
    public function __construct(
        public ?int $ruleId,
        public RuleTargetType $targetType,
        public int $targetId,
        public CompressionQualifier $compressionQualifier,
        public RuleAcceptance $acceptance,
        public ?ReferenceMode $referenceModeOverride,
        public ModifierOperation $modifierOperation,
        public ?BasisPoints $modifierBps,
    ) {
        $this->validate();
    }

    public function identity(): string
    {
        return $this->targetType === RuleTargetType::TYPE
            ? implode(':', [$this->targetType->value, (string) $this->targetId])
            : implode(':', [
                $this->targetType->value,
                (string) $this->targetId,
                $this->compressionQualifier->value,
            ]);
    }

    private function validate(): void
    {
        if ($this->ruleId !== null && $this->ruleId <= 0) {
            throw new InvalidPolicyRuleException('Rule ID must be positive when provided.');
        }

        if ($this->targetId <= 0) {
            throw new InvalidPolicyRuleException('GROUP and TYPE rules must use a positive target ID.');
        }

        if (
            $this->targetType === RuleTargetType::TYPE
            && $this->compressionQualifier !== CompressionQualifier::ANY
        ) {
            throw new InvalidPolicyRuleException(
                'TYPE rules identify one concrete TypeID and must use canonical qualifier ANY.',
            );
        }

        if ($this->modifierOperation === ModifierOperation::INHERIT && $this->modifierBps !== null) {
            throw new InvalidPolicyRuleException('INHERIT modifier rules must not provide a basis-point value.');
        }

        if ($this->modifierOperation !== ModifierOperation::INHERIT && $this->modifierBps === null) {
            throw new InvalidPolicyRuleException(sprintf(
                '%s modifier rules must provide a basis-point value.',
                $this->modifierOperation->value,
            ));
        }

        if (
            $this->acceptance === RuleAcceptance::INHERIT
            && $this->referenceModeOverride === null
            && $this->modifierOperation === ModifierOperation::INHERIT
        ) {
            throw new InvalidPolicyRuleException('Policy rules must change at least one field.');
        }
    }
}
