<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Persistence;

use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyRule;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ProgramPolicyDefaults;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final class PolicyInputMapper
{
    public function defaults(BuybackProgram $program): ProgramPolicyDefaults
    {
        return new ProgramPolicyDefaults(
            acceptance: $this->requireInstance($program->default_acceptance, Acceptance::class, 'default_acceptance'),
            referenceMode: $this->requireInstance(
                $program->default_reference_mode,
                ReferenceMode::class,
                'default_reference_mode',
            ),
            modifierBps: $this->requireInstance(
                $program->default_modifier_bps,
                BasisPoints::class,
                'default_modifier_bps',
            ),
        );
    }

    /**
     * @param iterable<BuybackRule> $rules
     * @return list<PolicyRule>
     */
    public function activeRules(iterable $rules): array
    {
        $mapped = [];

        foreach ($rules as $rule) {
            if (! $rule instanceof BuybackRule) {
                throw new InvalidArgumentException('Every persistence rule must be a BuybackRule.');
            }

            if (! $rule->enabled || $rule->archived_at !== null) {
                continue;
            }

            $mapped[] = new PolicyRule(
                ruleId: $rule->getKey() === null ? null : (int) $rule->getKey(),
                targetType: $this->requireInstance($rule->target_type, RuleTargetType::class, 'target_type'),
                targetId: (int) $rule->target_id,
                compressionQualifier: $this->requireInstance(
                    $rule->compression_qualifier,
                    CompressionQualifier::class,
                    'compression_qualifier',
                ),
                acceptance: $this->requireInstance($rule->acceptance, RuleAcceptance::class, 'acceptance'),
                referenceModeOverride: $rule->reference_mode_override === null
                    ? null
                    : $this->requireInstance(
                        $rule->reference_mode_override,
                        ReferenceMode::class,
                        'reference_mode_override',
                    ),
                modifierOperation: $this->requireInstance(
                    $rule->modifier_operation,
                    ModifierOperation::class,
                    'modifier_operation',
                ),
                modifierBps: $rule->modifier_bps === null
                    ? null
                    : $this->requireInstance($rule->modifier_bps, BasisPoints::class, 'modifier_bps'),
            );
        }

        return $mapped;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function requireInstance(mixed $value, string $class, string $field): object
    {
        if (! $value instanceof $class) {
            throw new InvalidArgumentException(sprintf(
                '%s must resolve to %s; %s given.',
                $field,
                $class,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
