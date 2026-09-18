<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules;

use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\PolicyLayerSource;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\DuplicatePolicyRuleException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidEffectivePolicyException;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;
use UnexpectedValueException;

final class PolicyEvaluator
{
    /**
     * @param iterable<PolicyRule> $rules
     */
    public function evaluate(
        ProgramPolicyDefaults $defaults,
        ItemPolicyContext $item,
        iterable $rules,
    ): EffectivePolicy {
        $rulesByIdentity = $this->indexRules($rules);
        $acceptance = $defaults->acceptance;
        $referenceMode = $defaults->referenceMode;
        $modifierBps = $defaults->modifierBps;
        $layers = [];

        foreach ($this->applicationPlan($item) as [$identity, $source, $reason]) {
            $rule = $rulesByIdentity[$identity] ?? null;

            if ($rule === null) {
                continue;
            }

            $acceptanceBefore = $acceptance;
            $referenceModeBefore = $referenceMode;
            $modifierBeforeBps = $modifierBps;

            $acceptance = $this->applyAcceptance($acceptance, $rule->acceptance);
            $referenceMode = $rule->referenceModeOverride ?? $referenceMode;
            $modifierBps = $this->applyModifier($modifierBps, $rule, $source);

            $layers[] = new AppliedPolicyLayer(
                source: $source,
                rule: $rule,
                matchReason: $reason,
                acceptanceOperation: $rule->acceptance,
                acceptanceBefore: $acceptanceBefore,
                acceptanceAfter: $acceptance,
                referenceModeOverride: $rule->referenceModeOverride,
                referenceModeBefore: $referenceModeBefore,
                referenceModeAfter: $referenceMode,
                modifierOperation: $rule->modifierOperation,
                modifierOperandBps: $rule->modifierBps,
                modifierBeforeBps: $modifierBeforeBps,
                modifierAfterBps: $modifierBps,
            );
        }

        return new EffectivePolicy(
            baseline: $defaults,
            acceptance: $acceptance,
            referenceMode: $referenceMode,
            effectiveModifierBps: $modifierBps,
            layers: $layers,
        );
    }

    /**
     * @param iterable<PolicyRule> $rules
     * @return array<string, PolicyRule>
     */
    private function indexRules(iterable $rules): array
    {
        $indexed = [];
        $duplicates = [];

        foreach ($rules as $rule) {
            if (! $rule instanceof PolicyRule) {
                throw new InvalidArgumentException('Every supplied rule must be a PolicyRule.');
            }

            $identity = $rule->identity();

            if (isset($indexed[$identity])) {
                $duplicates[$identity] = true;
            } else {
                $indexed[$identity] = $rule;
            }
        }

        if ($duplicates !== []) {
            $identities = array_keys($duplicates);
            sort($identities, SORT_STRING);

            throw new DuplicatePolicyRuleException(sprintf(
                'Duplicate policy rule layer(s): %s.',
                implode(', ', $identities),
            ));
        }

        return $indexed;
    }

    /**
     * @return list<array{string, PolicyLayerSource, string}>
     */
    private function applicationPlan(ItemPolicyContext $item): array
    {
        $plan = [
            $this->plannedLayer(
                RuleTargetType::GROUP,
                $item->groupId,
                CompressionQualifier::ANY,
                PolicyLayerSource::GROUP,
                sprintf('GROUP %d matches the item group with qualifier ANY.', $item->groupId),
            ),
        ];

        $matchingQualifier = $this->matchingQualifier($item->compressionState);

        if ($matchingQualifier !== null) {
            $plan[] = $this->plannedLayer(
                RuleTargetType::GROUP,
                $item->groupId,
                $matchingQualifier,
                PolicyLayerSource::GROUP_COMPRESSION,
                sprintf(
                    'GROUP %d matches the item group and runtime compression state %s.',
                    $item->groupId,
                    $item->compressionState->value,
                ),
            );
        }

        $plan[] = $this->plannedLayer(
            RuleTargetType::TYPE,
            $item->typeId,
            CompressionQualifier::ANY,
            PolicyLayerSource::TYPE,
            sprintf('TYPE %d matches the exact item type.', $item->typeId),
        );

        return $plan;
    }

    /**
     * @return array{string, PolicyLayerSource, string}
     */
    private function plannedLayer(
        RuleTargetType $targetType,
        int $targetId,
        CompressionQualifier $qualifier,
        PolicyLayerSource $source,
        string $reason,
    ): array {
        return [
            $targetType === RuleTargetType::TYPE
                ? implode(':', [$targetType->value, (string) $targetId])
                : implode(':', [$targetType->value, (string) $targetId, $qualifier->value]),
            $source,
            $reason,
        ];
    }

    private function matchingQualifier(CompressionState $state): ?CompressionQualifier
    {
        return match ($state) {
            CompressionState::COMPRESSED => CompressionQualifier::COMPRESSED,
            CompressionState::UNCOMPRESSED => CompressionQualifier::UNCOMPRESSED,
            CompressionState::NOT_APPLICABLE => null,
        };
    }

    private function applyAcceptance(Acceptance $current, RuleAcceptance $operation): Acceptance
    {
        return match ($operation) {
            RuleAcceptance::INHERIT => $current,
            RuleAcceptance::ACCEPT => Acceptance::ACCEPT,
            RuleAcceptance::REJECT => Acceptance::REJECT,
        };
    }

    private function applyModifier(
        BasisPoints $current,
        PolicyRule $rule,
        PolicyLayerSource $source,
    ): BasisPoints {
        $value = match ($rule->modifierOperation) {
            ModifierOperation::INHERIT => $current->value,
            ModifierOperation::REPLACE => $rule->modifierBps?->value
                ?? throw new UnexpectedValueException('Validated REPLACE rule has no modifier value.'),
            ModifierOperation::ADJUST => $current->value + ($rule->modifierBps?->value
                ?? throw new UnexpectedValueException('Validated ADJUST rule has no modifier value.')),
        };

        try {
            return new BasisPoints($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidEffectivePolicyException(new EffectiveModifierViolation(
                source: $source,
                ruleId: $rule->ruleId,
                targetType: $rule->targetType,
                targetId: $rule->targetId,
                compressionQualifier: $rule->compressionQualifier,
                operation: $rule->modifierOperation,
                previousBps: $current->value,
                operandBps: $rule->modifierBps?->value,
                resultingBps: $value,
            ), $exception);
        }
    }
}
