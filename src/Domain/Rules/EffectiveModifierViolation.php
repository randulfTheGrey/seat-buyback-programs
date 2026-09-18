<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\PolicyLayerSource;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final readonly class EffectiveModifierViolation
{
    public function __construct(
        public PolicyLayerSource $source,
        public ?int $ruleId,
        public RuleTargetType $targetType,
        public int $targetId,
        public CompressionQualifier $compressionQualifier,
        public ModifierOperation $operation,
        public int $previousBps,
        public ?int $operandBps,
        public int $resultingBps,
    ) {
    }

    public function bound(): string
    {
        return $this->resultingBps < BasisPoints::MIN ? 'minimum' : 'maximum';
    }

    public function excessBps(): int
    {
        return $this->resultingBps < BasisPoints::MIN
            ? BasisPoints::MIN - $this->resultingBps
            : $this->resultingBps - BasisPoints::MAX;
    }

    public function diagnosticIdentity(): string
    {
        return implode(':', [
            $this->ruleId === null ? 'unpersisted' : 'rule:' . $this->ruleId,
            $this->targetType->value,
            (string) $this->targetId,
            $this->compressionQualifier->value,
            $this->bound(),
        ]);
    }

    public function debugMessage(): string
    {
        return sprintf(
            'Applying %s rule%s produced %d basis points; effective modifiers must be between %d and %d.',
            $this->source->value,
            $this->ruleId === null ? '' : sprintf(' #%d', $this->ruleId),
            $this->resultingBps,
            BasisPoints::MIN,
            BasisPoints::MAX,
        );
    }
}
