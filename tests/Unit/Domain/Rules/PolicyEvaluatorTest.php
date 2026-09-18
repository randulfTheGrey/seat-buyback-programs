<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Domain\Rules;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ItemPolicyContext;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyEvaluator;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyRule;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ProgramPolicyDefaults;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\PolicyLayerSource;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\DuplicatePolicyRuleException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidEffectivePolicyException;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final class PolicyEvaluatorTest extends TestCase
{
    private PolicyEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new PolicyEvaluator();
    }

    /**
     * @return iterable<string, array{Acceptance}>
     */
    public static function defaultAcceptanceProvider(): iterable
    {
        yield 'global/default accept' => [Acceptance::ACCEPT];
        yield 'configurable global/default reject' => [Acceptance::REJECT];
    }

    #[DataProvider('defaultAcceptanceProvider')]
    public function test_program_defaults_apply_without_matching_rules(Acceptance $acceptance): void
    {
        $defaults = $this->defaults($acceptance, ReferenceMode::SPLIT, 275);

        $result = $this->evaluator->evaluate($defaults, $this->item(), []);

        self::assertSame($acceptance, $result->acceptance);
        self::assertSame(ReferenceMode::SPLIT, $result->referenceMode);
        self::assertSame(275, $result->effectiveModifierBps->value);
        self::assertSame([], $result->layers);
        self::assertSame($defaults, $result->baseline);
    }

    /**
     * @return iterable<string, array{Acceptance, RuleAcceptance, Acceptance}>
     */
    public static function groupAcceptanceProvider(): iterable
    {
        yield 'group reject overrides global accept' => [
            Acceptance::ACCEPT,
            RuleAcceptance::REJECT,
            Acceptance::REJECT,
        ];
        yield 'group accept overrides global reject' => [
            Acceptance::REJECT,
            RuleAcceptance::ACCEPT,
            Acceptance::ACCEPT,
        ];
    }

    #[DataProvider('groupAcceptanceProvider')]
    public function test_group_acceptance_overrides_default(
        Acceptance $default,
        RuleAcceptance $operation,
        Acceptance $expected,
    ): void {
        $result = $this->evaluator->evaluate(
            $this->defaults($default),
            $this->item(),
            [$this->rule(RuleTargetType::GROUP, 18, acceptance: $operation)],
        );

        self::assertSame($expected, $result->acceptance);
    }

    public function test_group_changes_reference_while_other_fields_inherit(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(Acceptance::ACCEPT, ReferenceMode::BUY, -450),
            $this->item(),
            [$this->rule(RuleTargetType::GROUP, 18, referenceMode: ReferenceMode::SELL)],
        );

        self::assertSame(Acceptance::ACCEPT, $result->acceptance);
        self::assertSame(ReferenceMode::SELL, $result->referenceMode);
        self::assertSame(-450, $result->effectiveModifierBps->value);
    }

    public function test_group_changes_modifier_while_reference_inherits(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(Acceptance::ACCEPT, ReferenceMode::SPLIT, -1000),
            $this->item(),
            [$this->rule(RuleTargetType::GROUP, 18, modifierOperation: ModifierOperation::ADJUST, modifierBps: -500)],
        );

        self::assertSame(ReferenceMode::SPLIT, $result->referenceMode);
        self::assertSame(-1500, $result->effectiveModifierBps->value);
    }

    /**
     * @return iterable<string, array{RuleAcceptance, RuleAcceptance, Acceptance}>
     */
    public static function rejectionChainProvider(): iterable
    {
        yield 'type accept re-accepts group reject' => [
            RuleAcceptance::REJECT,
            RuleAcceptance::ACCEPT,
            Acceptance::ACCEPT,
        ];
        yield 'type reject re-rejects group accept' => [
            RuleAcceptance::ACCEPT,
            RuleAcceptance::REJECT,
            Acceptance::REJECT,
        ];
    }

    #[DataProvider('rejectionChainProvider')]
    public function test_type_acceptance_overrides_group_without_rejection_short_circuit(
        RuleAcceptance $group,
        RuleAcceptance $type,
        Acceptance $expected,
    ): void {
        $default = $group === RuleAcceptance::REJECT ? Acceptance::ACCEPT : Acceptance::REJECT;
        $result = $this->evaluator->evaluate($this->defaults($default), $this->item(), [
            $this->rule(RuleTargetType::GROUP, 18, acceptance: $group),
            $this->rule(RuleTargetType::TYPE, 34, acceptance: $type),
        ]);

        self::assertSame($expected, $result->acceptance);
        self::assertCount(2, $result->layers);
    }

    public function test_type_reference_and_modifier_replacements_win_over_group_values(): void
    {
        $result = $this->evaluator->evaluate($this->defaults(), $this->item(), [
            $this->rule(
                RuleTargetType::GROUP,
                18,
                referenceMode: ReferenceMode::SELL,
                modifierOperation: ModifierOperation::REPLACE,
                modifierBps: -700,
            ),
            $this->rule(
                RuleTargetType::TYPE,
                34,
                referenceMode: ReferenceMode::SPLIT,
                modifierOperation: ModifierOperation::REPLACE,
                modifierBps: 250,
            ),
        ]);

        self::assertSame(ReferenceMode::SPLIT, $result->referenceMode);
        self::assertSame(250, $result->effectiveModifierBps->value);
    }

    /**
     * @return iterable<string, array{CompressionQualifier, CompressionState, bool}>
     */
    public static function compressionMatchProvider(): iterable
    {
        yield 'uncompressed matches uncompressed' => [
            CompressionQualifier::UNCOMPRESSED,
            CompressionState::UNCOMPRESSED,
            true,
        ];
        yield 'uncompressed does not match compressed' => [
            CompressionQualifier::UNCOMPRESSED,
            CompressionState::COMPRESSED,
            false,
        ];
        yield 'uncompressed does not match not applicable' => [
            CompressionQualifier::UNCOMPRESSED,
            CompressionState::NOT_APPLICABLE,
            false,
        ];
        yield 'compressed matches compressed' => [
            CompressionQualifier::COMPRESSED,
            CompressionState::COMPRESSED,
            true,
        ];
        yield 'compressed does not match uncompressed' => [
            CompressionQualifier::COMPRESSED,
            CompressionState::UNCOMPRESSED,
            false,
        ];
        yield 'compressed does not match not applicable' => [
            CompressionQualifier::COMPRESSED,
            CompressionState::NOT_APPLICABLE,
            false,
        ];
    }

    #[DataProvider('compressionMatchProvider')]
    public function test_group_compression_qualifiers_match_exact_runtime_state(
        CompressionQualifier $qualifier,
        CompressionState $state,
        bool $matches,
    ): void {
        $result = $this->evaluator->evaluate(
            $this->defaults(),
            $this->item($state),
            [$this->rule(RuleTargetType::GROUP, 18, $qualifier, acceptance: RuleAcceptance::REJECT)],
        );

        self::assertSame($matches ? Acceptance::REJECT : Acceptance::ACCEPT, $result->acceptance);
        self::assertCount($matches ? 1 : 0, $result->layers);
    }

    public function test_not_applicable_matches_only_any_layers(): void
    {
        $result = $this->evaluator->evaluate($this->defaults(), $this->item(CompressionState::NOT_APPLICABLE), [
            $this->rule(RuleTargetType::GROUP, 18, referenceMode: ReferenceMode::SELL, ruleId: 1),
            $this->rule(
                RuleTargetType::GROUP,
                18,
                CompressionQualifier::COMPRESSED,
                acceptance: RuleAcceptance::REJECT,
                ruleId: 2,
            ),
            $this->rule(
                RuleTargetType::TYPE,
                34,
                modifierOperation: ModifierOperation::ADJUST,
                modifierBps: 100,
                ruleId: 3,
            ),
        ]);

        self::assertSame(ReferenceMode::SELL, $result->referenceMode);
        self::assertSame(Acceptance::ACCEPT, $result->acceptance);
        self::assertSame(100, $result->effectiveModifierBps->value);
        self::assertSame([1, 3], array_map(static fn ($layer): ?int => $layer->rule->ruleId, $result->layers));
    }

    public function test_type_outranks_compression_qualified_group(): void
    {
        $result = $this->evaluator->evaluate($this->defaults(), $this->item(CompressionState::COMPRESSED), [
            $this->rule(
                RuleTargetType::GROUP,
                18,
                CompressionQualifier::COMPRESSED,
                acceptance: RuleAcceptance::REJECT,
            ),
            $this->rule(RuleTargetType::TYPE, 34, acceptance: RuleAcceptance::ACCEPT),
        ]);

        self::assertSame(Acceptance::ACCEPT, $result->acceptance);
        self::assertSame(
            [PolicyLayerSource::GROUP_COMPRESSION, PolicyLayerSource::TYPE],
            array_map(static fn ($layer): PolicyLayerSource => $layer->source, $result->layers),
        );
    }

    public function test_qualified_and_unqualified_group_layers_both_contribute(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(modifierBps: -1000),
            $this->item(CompressionState::COMPRESSED),
            [
                $this->rule(RuleTargetType::GROUP, 18, referenceMode: ReferenceMode::SELL),
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    CompressionQualifier::COMPRESSED,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: -200,
                ),
            ],
        );

        self::assertSame(ReferenceMode::SELL, $result->referenceMode);
        self::assertSame(-1200, $result->effectiveModifierBps->value);
        self::assertSame(
            [PolicyLayerSource::GROUP, PolicyLayerSource::GROUP_COMPRESSION],
            array_map(static fn ($layer): PolicyLayerSource => $layer->source, $result->layers),
        );
    }

    public function test_type_rule_applies_once_independently_of_intrinsic_compression_state(): void
    {
        foreach (CompressionState::cases() as $state) {
            $result = $this->evaluator->evaluate($this->defaults(), $this->item($state), [
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    referenceMode: ReferenceMode::SELL,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 300,
                ),
            ]);

            self::assertSame(ReferenceMode::SELL, $result->referenceMode);
            self::assertSame(300, $result->effectiveModifierBps->value);
            self::assertSame([PolicyLayerSource::TYPE], array_column($result->layers, 'source'));
            self::assertSame(['TYPE'], array_column($result->toArray()['layers'], 'source'));
        }
    }

    /**
     * @return iterable<string, array{int, ModifierOperation, ?int, int}>
     */
    public static function modifierOperationProvider(): iterable
    {
        yield 'inherit' => [-1000, ModifierOperation::INHERIT, null, -1000];
        yield 'replace discount' => [500, ModifierOperation::REPLACE, -300, -300];
        yield 'replace premium' => [-500, ModifierOperation::REPLACE, 300, 300];
        yield 'adjust discount' => [-1000, ModifierOperation::ADJUST, -500, -1500];
        yield 'adjust premium' => [200, ModifierOperation::ADJUST, 300, 500];
    }

    #[DataProvider('modifierOperationProvider')]
    public function test_modifier_operations_are_exact(
        int $initial,
        ModifierOperation $operation,
        ?int $operand,
        int $expected,
    ): void {
        $rule = $operation === ModifierOperation::INHERIT
            ? $this->rule(RuleTargetType::GROUP, 18, acceptance: RuleAcceptance::ACCEPT)
            : $this->rule(RuleTargetType::GROUP, 18, modifierOperation: $operation, modifierBps: $operand);

        $result = $this->evaluator->evaluate($this->defaults(modifierBps: $initial), $this->item(), [$rule]);

        self::assertSame($expected, $result->effectiveModifierBps->value);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function replacementRegressionProvider(): iterable
    {
        yield 'discount boundary ignores inherited discount' => [-1000, -10000];
        yield 'premium boundary ignores inherited premium' => [1000, 10000];
        yield 'replacement can cross from discount to premium' => [-9000, 9000];
    }

    #[DataProvider('replacementRegressionProvider')]
    public function test_replace_uses_the_operand_instead_of_composing_with_the_inherited_modifier(
        int $initial,
        int $replacement,
    ): void {
        $result = $this->evaluator->evaluate(
            $this->defaults(modifierBps: $initial),
            $this->item(),
            [$this->rule(
                RuleTargetType::TYPE,
                34,
                modifierOperation: ModifierOperation::REPLACE,
                modifierBps: $replacement,
            )],
        );

        self::assertSame($replacement, $result->effectiveModifierBps->value);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function adjustmentRegressionProvider(): iterable
    {
        yield 'discount exact boundary' => [-1000, -9000, -10000];
        yield 'premium exact boundary' => [1000, 9000, 10000];
    }

    #[DataProvider('adjustmentRegressionProvider')]
    public function test_adjust_composes_with_the_inherited_modifier_at_exact_boundaries(
        int $initial,
        int $adjustment,
        int $expected,
    ): void {
        $result = $this->evaluator->evaluate(
            $this->defaults(modifierBps: $initial),
            $this->item(),
            [$this->rule(
                RuleTargetType::TYPE,
                34,
                modifierOperation: ModifierOperation::ADJUST,
                modifierBps: $adjustment,
            )],
        );

        self::assertSame($expected, $result->effectiveModifierBps->value);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function adjustmentImmediateOverflowRegressionProvider(): iterable
    {
        yield 'one point below discount boundary' => [-1000, -9001, -10001];
        yield 'one point above premium boundary' => [1000, 9001, 10001];
    }

    #[DataProvider('adjustmentImmediateOverflowRegressionProvider')]
    public function test_adjust_fails_closed_immediately_outside_the_boundaries(
        int $initial,
        int $adjustment,
        int $invalid,
    ): void {
        $this->expectException(InvalidEffectivePolicyException::class);
        $this->expectExceptionMessage(sprintf('produced %d basis points', $invalid));

        $this->evaluator->evaluate(
            $this->defaults(modifierBps: $initial),
            $this->item(),
            [$this->rule(
                RuleTargetType::TYPE,
                34,
                modifierOperation: ModifierOperation::ADJUST,
                modifierBps: $adjustment,
            )],
        );
    }

    public function test_type_replacement_resets_prior_program_and_group_adjustments(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(modifierBps: -1000),
            $this->item(),
            [
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: -2000,
                ),
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    modifierOperation: ModifierOperation::REPLACE,
                    modifierBps: -5000,
                ),
            ],
        );

        self::assertSame(-5000, $result->effectiveModifierBps->value);
        self::assertSame([-3000, -5000], array_map(
            static fn ($layer): int => $layer->modifierAfterBps->value,
            $result->layers,
        ));
    }

    public function test_effective_policy_exception_exposes_the_structured_offending_operation(): void
    {
        try {
            $this->evaluator->evaluate(
                $this->defaults(modifierBps: -1000),
                $this->item(),
                [$this->rule(
                    RuleTargetType::TYPE,
                    34,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: -10000,
                    ruleId: 77,
                )],
            );
            self::fail('Expected effective modifier composition to fail.');
        } catch (InvalidEffectivePolicyException $exception) {
            self::assertSame(ModifierOperation::ADJUST, $exception->violation->operation);
            self::assertSame(-1000, $exception->violation->previousBps);
            self::assertSame(-10000, $exception->violation->operandBps);
            self::assertSame(-11000, $exception->violation->resultingBps);
            self::assertSame(77, $exception->violation->ruleId);
        }
    }

    public function test_multiple_adjust_layers_accumulate_additive_percentage_points(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(modifierBps: -1000),
            $this->item(CompressionState::COMPRESSED),
            [
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: -100,
                ),
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    CompressionQualifier::COMPRESSED,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: -200,
                ),
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: -200,
                ),
            ],
        );

        self::assertSame(-1500, $result->effectiveModifierBps->value);
    }

    public function test_replace_after_adjust_resets_modifier_chain(): void
    {
        $result = $this->evaluator->evaluate($this->defaults(modifierBps: -1000), $this->item(), [
            $this->rule(RuleTargetType::GROUP, 18, modifierOperation: ModifierOperation::ADJUST, modifierBps: -500),
            $this->rule(RuleTargetType::TYPE, 34, modifierOperation: ModifierOperation::REPLACE, modifierBps: 250),
        ]);

        self::assertSame(250, $result->effectiveModifierBps->value);
    }

    public function test_adjust_after_replace_uses_replaced_value(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(modifierBps: -1000),
            $this->item(CompressionState::COMPRESSED),
            [
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    modifierOperation: ModifierOperation::REPLACE,
                    modifierBps: 200,
                ),
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 300,
                ),
            ],
        );

        self::assertSame(500, $result->effectiveModifierBps->value);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function effectiveOverflowProvider(): iterable
    {
        yield 'discount overflow' => [-9000, -2000, -11000];
        yield 'premium overflow' => [9500, 1000, 10500];
    }

    #[DataProvider('effectiveOverflowProvider')]
    public function test_effective_modifier_out_of_range_fails_explicitly(
        int $initial,
        int $adjustment,
        int $invalid,
    ): void {
        $this->expectException(InvalidEffectivePolicyException::class);
        $this->expectExceptionMessage(sprintf('produced %d basis points', $invalid));

        $this->evaluator->evaluate(
            $this->defaults(modifierBps: $initial),
            $this->item(),
            [$this->rule(
                RuleTargetType::GROUP,
                18,
                modifierOperation: ModifierOperation::ADJUST,
                modifierBps: $adjustment,
                ruleId: 91,
            )],
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function effectiveBoundaryProvider(): iterable
    {
        yield 'maximum premium' => [1, 10000];
        yield 'maximum discount' => [-1, -10000];
    }

    #[DataProvider('effectiveBoundaryProvider')]
    public function test_multi_level_adjustments_accept_exact_effective_boundaries(
        int $direction,
        int $expected,
    ): void {
        $result = $this->evaluator->evaluate(
            $this->defaults(modifierBps: 8000 * $direction),
            $this->item(CompressionState::COMPRESSED),
            [
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 500 * $direction,
                ),
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    CompressionQualifier::COMPRESSED,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 500 * $direction,
                ),
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 1000 * $direction,
                ),
            ],
        );

        self::assertSame($expected, $result->effectiveModifierBps->value);
        self::assertSame([8500, 9000, 10000], array_map(
            static fn ($layer): int => abs($layer->modifierAfterBps->value),
            $result->layers,
        ));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function effectiveImmediateOverflowProvider(): iterable
    {
        yield 'one basis point above maximum' => [1, 10001];
        yield 'one basis point below minimum' => [-1, -10001];
    }

    #[DataProvider('effectiveImmediateOverflowProvider')]
    public function test_individually_valid_multi_level_rules_cannot_compose_one_point_outside_bounds(
        int $direction,
        int $invalid,
    ): void {
        $this->expectException(InvalidEffectivePolicyException::class);
        $this->expectExceptionMessage(sprintf('produced %d basis points', $invalid));

        $this->evaluator->evaluate(
            $this->defaults(modifierBps: 8000 * $direction),
            $this->item(CompressionState::COMPRESSED),
            [
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 500 * $direction,
                ),
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    CompressionQualifier::COMPRESSED,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 500 * $direction,
                ),
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 1001 * $direction,
                    ruleId: 92,
                ),
            ],
        );
    }

    public function test_invalid_intermediate_adjustment_fails_even_when_later_replace_would_restore_range(): void
    {
        $this->expectException(InvalidEffectivePolicyException::class);
        $this->expectExceptionMessage('produced 11000 basis points');

        $this->evaluator->evaluate(
            $this->defaults(modifierBps: 8000),
            $this->item(),
            [
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    modifierOperation: ModifierOperation::ADJUST,
                    modifierBps: 3000,
                ),
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    modifierOperation: ModifierOperation::REPLACE,
                    modifierBps: 0,
                ),
            ],
        );
    }

    public function test_program_baseline_and_all_three_sparse_layers_apply_in_normative_order(): void
    {
        $rules = $this->allSparseRules();
        $result = $this->evaluator->evaluate($this->defaults(), $this->item(CompressionState::COMPRESSED), $rules);

        self::assertSame(PolicyLayerSource::PROGRAM_DEFAULTS->value, $result->toArray()['baseline']['source']);
        self::assertSame(
            [
                PolicyLayerSource::GROUP,
                PolicyLayerSource::GROUP_COMPRESSION,
                PolicyLayerSource::TYPE,
            ],
            array_map(static fn ($layer): PolicyLayerSource => $layer->source, $result->layers),
        );
        self::assertSame(
            [4, 90, 2],
            array_map(static fn ($layer): ?int => $layer->rule->ruleId, $result->layers),
            'Rule IDs do not act as priority or tie-break fields.',
        );
        self::assertSame(Acceptance::ACCEPT, $result->acceptance);
        self::assertSame(ReferenceMode::SELL, $result->referenceMode);
        self::assertSame(-100, $result->effectiveModifierBps->value);
    }

    public function test_type_reaccepts_after_qualified_group_and_replaces_modifier_in_four_layer_regression(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(Acceptance::ACCEPT, ReferenceMode::SPLIT, -1000),
            $this->item(CompressionState::UNCOMPRESSED),
            [
                $this->rule(RuleTargetType::GROUP, 18, referenceMode: ReferenceMode::BUY, ruleId: 10),
                $this->rule(
                    RuleTargetType::GROUP,
                    18,
                    CompressionQualifier::UNCOMPRESSED,
                    acceptance: RuleAcceptance::REJECT,
                    ruleId: 11,
                ),
                $this->rule(
                    RuleTargetType::TYPE,
                    34,
                    acceptance: RuleAcceptance::ACCEPT,
                    modifierOperation: ModifierOperation::REPLACE,
                    modifierBps: -500,
                    ruleId: 12,
                ),
            ],
        );

        self::assertSame(Acceptance::ACCEPT, $result->acceptance);
        self::assertSame(ReferenceMode::BUY, $result->referenceMode);
        self::assertSame(-500, $result->effectiveModifierBps->value);
        self::assertSame(
            [PolicyLayerSource::GROUP, PolicyLayerSource::GROUP_COMPRESSION, PolicyLayerSource::TYPE],
            array_column($result->layers, 'source'),
        );
        self::assertSame(
            ['PROGRAM_DEFAULTS', 'GROUP', 'GROUP_COMPRESSION', 'TYPE'],
            [$result->toArray()['baseline']['source'], ...array_column($result->toArray()['layers'], 'source')],
        );
        self::assertNotContains('TYPE_COMPRESSION', array_column($result->toArray()['layers'], 'source'));
    }

    public function test_input_collection_order_does_not_affect_result_or_explanation(): void
    {
        $rules = $this->allSparseRules();
        $forward = $this->evaluator->evaluate($this->defaults(), $this->item(CompressionState::COMPRESSED), $rules);
        $reverse = $this->evaluator->evaluate(
            $this->defaults(),
            $this->item(CompressionState::COMPRESSED),
            array_reverse($rules),
        );

        self::assertSame($forward->toArray(), $reverse->toArray());
    }

    public function test_duplicate_logical_layers_fail_with_collection_order_independent_message(): void
    {
        $rules = [
            $this->rule(RuleTargetType::TYPE, 34, acceptance: RuleAcceptance::ACCEPT, ruleId: 90),
            $this->rule(RuleTargetType::TYPE, 34, acceptance: RuleAcceptance::REJECT, ruleId: 2),
            $this->rule(RuleTargetType::GROUP, 18, referenceMode: ReferenceMode::SELL, ruleId: 70),
            $this->rule(RuleTargetType::GROUP, 18, referenceMode: ReferenceMode::BUY, ruleId: 4),
        ];

        $messages = [];
        foreach ([$rules, array_reverse($rules)] as $orderedRules) {
            try {
                $this->evaluator->evaluate($this->defaults(), $this->item(), $orderedRules);
                self::fail('Duplicate rules should fail evaluation.');
            } catch (DuplicatePolicyRuleException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        self::assertSame($messages[0], $messages[1]);
        self::assertSame(
            'Duplicate policy rule layer(s): GROUP:18:ANY, TYPE:34.',
            $messages[0],
        );
    }

    public function test_explanation_preserves_provenance_field_operations_and_values(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(Acceptance::REJECT, ReferenceMode::BUY, -1000),
            $this->item(CompressionState::UNCOMPRESSED),
            [$this->rule(
                RuleTargetType::GROUP,
                18,
                acceptance: RuleAcceptance::ACCEPT,
                referenceMode: ReferenceMode::SELL,
                modifierOperation: ModifierOperation::ADJUST,
                modifierBps: -200,
                ruleId: 42,
            )],
        );

        self::assertSame([
            'baseline' => [
                'source' => 'PROGRAM_DEFAULTS',
                'semantic_layer' => 'GLOBAL',
                'rule_id' => null,
                'match_reason' => 'Program defaults are the sole GLOBAL policy baseline.',
                'acceptance' => [
                    'operation' => 'DEFAULT',
                    'value' => 'REJECT',
                ],
                'reference_mode' => [
                    'operation' => 'DEFAULT',
                    'value' => 'BUY',
                ],
                'modifier' => [
                    'operation' => 'DEFAULT',
                    'value_bps' => -1000,
                ],
            ],
            'acceptance' => 'ACCEPT',
            'reference_mode' => 'SELL',
            'effective_modifier_bps' => -1200,
            'layers' => [[
                'source' => 'GROUP',
                'rule_id' => 42,
                'target_type' => 'GROUP',
                'target_id' => 18,
                'compression_qualifier' => 'ANY',
                'match_reason' => 'GROUP 18 matches the item group with qualifier ANY.',
                'acceptance' => [
                    'operation' => 'ACCEPT',
                    'before' => 'REJECT',
                    'after' => 'ACCEPT',
                    'applied' => true,
                ],
                'reference_mode' => [
                    'operation' => 'OVERRIDE',
                    'value' => 'SELL',
                    'before' => 'BUY',
                    'after' => 'SELL',
                    'applied' => true,
                ],
                'modifier' => [
                    'operation' => 'ADJUST',
                    'value_bps' => -200,
                    'before_bps' => -1000,
                    'after_bps' => -1200,
                    'applied' => true,
                ],
            ]],
        ], $result->toArray());
    }

    public function test_program_defaults_are_exactly_one_global_policy_contribution(): void
    {
        $result = $this->evaluator->evaluate(
            $this->defaults(Acceptance::ACCEPT, ReferenceMode::SPLIT, -1000),
            $this->item(),
            [],
        );

        self::assertSame(Acceptance::ACCEPT, $result->acceptance);
        self::assertSame(ReferenceMode::SPLIT, $result->referenceMode);
        self::assertSame(-1000, $result->effectiveModifierBps->value);
        self::assertSame([], $result->layers);
        $explanation = $result->toArray();
        self::assertSame('PROGRAM_DEFAULTS', $explanation['baseline']['source']);
        self::assertSame('GLOBAL', $explanation['baseline']['semantic_layer']);
        self::assertArrayNotHasKey('defaults', $explanation);
        self::assertCount(1, [$explanation['baseline'], ...$explanation['layers']]);
    }

    private function defaults(
        Acceptance $acceptance = Acceptance::ACCEPT,
        ReferenceMode $referenceMode = ReferenceMode::BUY,
        int $modifierBps = 0,
    ): ProgramPolicyDefaults {
        return new ProgramPolicyDefaults($acceptance, $referenceMode, new BasisPoints($modifierBps));
    }

    private function item(CompressionState $state = CompressionState::UNCOMPRESSED): ItemPolicyContext
    {
        return new ItemPolicyContext(typeId: 34, groupId: 18, compressionState: $state);
    }

    private function rule(
        RuleTargetType $targetType,
        int $targetId,
        CompressionQualifier $qualifier = CompressionQualifier::ANY,
        RuleAcceptance $acceptance = RuleAcceptance::INHERIT,
        ?ReferenceMode $referenceMode = null,
        ModifierOperation $modifierOperation = ModifierOperation::INHERIT,
        ?int $modifierBps = null,
        ?int $ruleId = null,
    ): PolicyRule {
        return new PolicyRule(
            ruleId: $ruleId,
            targetType: $targetType,
            targetId: $targetId,
            compressionQualifier: $qualifier,
            acceptance: $acceptance,
            referenceModeOverride: $referenceMode,
            modifierOperation: $modifierOperation,
            modifierBps: $modifierBps === null ? null : new BasisPoints($modifierBps),
        );
    }

    /**
     * @return list<PolicyRule>
     */
    private function allSparseRules(): array
    {
        return [
            $this->rule(RuleTargetType::TYPE, 34, acceptance: RuleAcceptance::ACCEPT, ruleId: 2),
            $this->rule(
                RuleTargetType::GROUP,
                18,
                referenceMode: ReferenceMode::SELL,
                modifierOperation: ModifierOperation::ADJUST,
                modifierBps: -100,
                ruleId: 4,
            ),
            $this->rule(
                RuleTargetType::GROUP,
                18,
                CompressionQualifier::COMPRESSED,
                acceptance: RuleAcceptance::REJECT,
                ruleId: 90,
            ),
        ];
    }
}
