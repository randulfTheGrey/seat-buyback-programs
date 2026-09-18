<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\EffectiveModifierViolation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;

final readonly class EffectivePolicyDiagnostic
{
    public function __construct(
        public EffectiveModifierViolation $violation,
        public string $targetName,
    ) {
    }

    public function identity(): string
    {
        return $this->violation->diagnosticIdentity();
    }

    public function managerMessage(): string
    {
        $scope = $this->violation->targetType === RuleTargetType::TYPE
            ? sprintf('item type “%s”', $this->targetName)
            : sprintf('item group “%s”%s', $this->targetName, $this->qualifierDescription());

        $effect = $this->violation->operation === ModifierOperation::ADJUST
            ? sprintf(
                'would adjust %s by %s, producing %s',
                $this->formatModifier($this->violation->previousBps),
                $this->formatModifier($this->violation->operandBps ?? 0),
                $this->formatModifier($this->violation->resultingBps),
            )
            : sprintf('would produce %s', $this->formatModifier($this->violation->resultingBps));

        return sprintf(
            'The %s modifier for %s %s. Effective modifiers must stay between 100%% discount and 100%% premium.',
            $this->violation->operation->value,
            $scope,
            $effect,
        );
    }

    private function qualifierDescription(): string
    {
        return match ($this->violation->compressionQualifier) {
            CompressionQualifier::ANY => '',
            CompressionQualifier::COMPRESSED => ' (compressed items)',
            CompressionQualifier::UNCOMPRESSED => ' (uncompressed items)',
        };
    }

    private function formatModifier(int $basisPoints): string
    {
        if ($basisPoints === 0) {
            return '0%';
        }

        $percentage = rtrim(rtrim(number_format(abs($basisPoints) / 100, 2, '.', ''), '0'), '.');

        return sprintf('%s%% %s', $percentage, $basisPoints < 0 ? 'discount' : 'premium');
    }
}
