<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Domain\Rules;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ItemPolicyContext;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyRule;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidPolicyRuleException;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

final class PolicyRuleValidationTest extends TestCase
{
    public function test_no_op_rule_is_rejected(): void
    {
        $this->expectException(InvalidPolicyRuleException::class);
        $this->expectExceptionMessage('must change at least one field');

        $this->newRule();
    }

    /**
     * @return iterable<string, array{RuleTargetType, int, CompressionQualifier, string}>
     */
    public static function invalidTargetProvider(): iterable
    {
        yield 'group zero target' => [RuleTargetType::GROUP, 0, CompressionQualifier::ANY, 'positive target ID'];
        yield 'type negative target' => [RuleTargetType::TYPE, -1, CompressionQualifier::ANY, 'positive target ID'];
    }

    #[DataProvider('invalidTargetProvider')]
    public function test_structurally_invalid_targets_are_rejected(
        RuleTargetType $targetType,
        int $targetId,
        CompressionQualifier $qualifier,
        string $message,
    ): void {
        $this->expectException(InvalidPolicyRuleException::class);
        $this->expectExceptionMessage($message);

        $this->newRule(
            targetType: $targetType,
            targetId: $targetId,
            qualifier: $qualifier,
            acceptance: RuleAcceptance::ACCEPT,
        );
    }

    public function test_rule_id_must_be_positive_when_present(): void
    {
        $this->expectException(InvalidPolicyRuleException::class);
        $this->expectExceptionMessage('Rule ID must be positive');

        $this->newRule(acceptance: RuleAcceptance::ACCEPT, ruleId: 0);
    }

    /** @return iterable<string, array{CompressionQualifier}> */
    public static function typeCompressionQualifierProvider(): iterable
    {
        yield 'compressed' => [CompressionQualifier::COMPRESSED];
        yield 'uncompressed' => [CompressionQualifier::UNCOMPRESSED];
    }

    #[DataProvider('typeCompressionQualifierProvider')]
    public function test_type_rule_rejects_independent_compression_qualifier(
        CompressionQualifier $qualifier,
    ): void {
        $this->expectException(InvalidPolicyRuleException::class);
        $this->expectExceptionMessage('must use canonical qualifier ANY');

        $this->newRule(qualifier: $qualifier, acceptance: RuleAcceptance::ACCEPT);
    }

    public function test_inherit_modifier_rejects_a_value(): void
    {
        $this->expectException(InvalidPolicyRuleException::class);
        $this->expectExceptionMessage('must not provide');

        $this->newRule(acceptance: RuleAcceptance::ACCEPT, modifierBps: 100);
    }

    /**
     * @return iterable<string, array{ModifierOperation}>
     */
    public static function valuedModifierOperationProvider(): iterable
    {
        yield 'replace' => [ModifierOperation::REPLACE];
        yield 'adjust' => [ModifierOperation::ADJUST];
    }

    #[DataProvider('valuedModifierOperationProvider')]
    public function test_valued_modifier_operations_require_a_value(ModifierOperation $operation): void
    {
        $this->expectException(InvalidPolicyRuleException::class);
        $this->expectExceptionMessage('must provide a basis-point value');

        $this->newRule(modifierOperation: $operation);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidConfiguredModifierProvider(): iterable
    {
        yield 'below minimum' => [-10001];
        yield 'above maximum' => [10001];
    }

    #[DataProvider('invalidConfiguredModifierProvider')]
    public function test_configured_modifier_bounds_are_enforced(int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Basis points must be between -10000 and 10000');

        new BasisPoints($value);
    }

    public function test_modifier_boundary_values_are_valid(): void
    {
        self::assertSame(-10000, (new BasisPoints(-10000))->value);
        self::assertSame(10000, (new BasisPoints(10000))->value);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidItemIdProvider(): iterable
    {
        yield 'invalid type' => [0, 18];
        yield 'invalid group' => [34, 0];
    }

    #[DataProvider('invalidItemIdProvider')]
    public function test_item_context_requires_positive_resolved_ids(int $typeId, int $groupId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ItemPolicyContext($typeId, $groupId, CompressionState::NOT_APPLICABLE);
    }

    private function newRule(
        RuleTargetType $targetType = RuleTargetType::TYPE,
        int $targetId = 34,
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
}
