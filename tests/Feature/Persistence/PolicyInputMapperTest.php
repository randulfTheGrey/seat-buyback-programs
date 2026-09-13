<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Persistence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Persistence\PolicyInputMapper;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class PolicyInputMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapper_translates_program_defaults_and_only_active_sparse_rules(): void
    {
        $program = BuybackProgram::create([
            'name' => 'Mapper Program',
            'default_acceptance' => Acceptance::REJECT,
            'default_reference_mode' => ReferenceMode::SPLIT,
            'default_modifier_bps' => -850,
            'quote_validity_minutes' => 30,
        ]);

        $group = $program->rules()->create([
            'target_type' => RuleTargetType::GROUP,
            'target_id' => 18,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::ACCEPT,
            'reference_mode_override' => null,
            'modifier_operation' => ModifierOperation::ADJUST,
            'modifier_bps' => 100,
        ]);
        $program->rules()->create([
            'target_type' => RuleTargetType::GROUP,
            'target_id' => 19,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::REJECT,
            'modifier_operation' => ModifierOperation::INHERIT,
            'enabled' => false,
        ]);
        $program->rules()->create([
            'target_type' => RuleTargetType::TYPE,
            'target_id' => 34,
            'compression_qualifier' => CompressionQualifier::ANY,
            'acceptance' => RuleAcceptance::REJECT,
            'modifier_operation' => ModifierOperation::INHERIT,
            'archived_at' => '2026-09-10 12:00:00',
        ]);

        $mapper = new PolicyInputMapper();
        $defaults = $mapper->defaults($program->fresh());
        $rules = $mapper->activeRules($program->rules()->get());

        self::assertSame(Acceptance::REJECT, $defaults->acceptance);
        self::assertSame(ReferenceMode::SPLIT, $defaults->referenceMode);
        self::assertSame(-850, $defaults->modifierBps->value);
        self::assertCount(1, $rules);
        self::assertSame($group->id, $rules[0]->ruleId);
        self::assertSame(RuleTargetType::GROUP, $rules[0]->targetType);
        self::assertSame(18, $rules[0]->targetId);
        self::assertSame(CompressionQualifier::ANY, $rules[0]->compressionQualifier);
        self::assertSame(RuleAcceptance::ACCEPT, $rules[0]->acceptance);
        self::assertNull($rules[0]->referenceModeOverride);
        self::assertSame(ModifierOperation::ADJUST, $rules[0]->modifierOperation);
        self::assertSame(100, $rules[0]->modifierBps?->value);
    }

    public function test_mapper_loads_one_canonical_type_rule_alongside_group_qualifier_layers(): void
    {
        $program = BuybackProgram::create([
            'name' => 'Layer Program',
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 30,
        ]);
        $program->rules()->createMany([
            [
                'target_type' => RuleTargetType::GROUP,
                'target_id' => 18,
                'compression_qualifier' => CompressionQualifier::ANY,
                'acceptance' => RuleAcceptance::ACCEPT,
                'modifier_operation' => ModifierOperation::INHERIT,
            ],
            [
                'target_type' => RuleTargetType::GROUP,
                'target_id' => 18,
                'compression_qualifier' => CompressionQualifier::UNCOMPRESSED,
                'acceptance' => RuleAcceptance::REJECT,
                'modifier_operation' => ModifierOperation::INHERIT,
            ],
            [
                'target_type' => RuleTargetType::TYPE,
                'target_id' => 34,
                'compression_qualifier' => CompressionQualifier::ANY,
                'acceptance' => RuleAcceptance::ACCEPT,
                'modifier_operation' => ModifierOperation::INHERIT,
            ],
        ]);

        $rules = (new PolicyInputMapper())->activeRules($program->rules()->get());

        self::assertSame(
            ['GROUP:18:ANY', 'GROUP:18:UNCOMPRESSED', 'TYPE:34'],
            array_map(static fn ($rule): string => $rule->identity(), $rules),
        );
        self::assertCount(1, array_filter(
            $rules,
            static fn ($rule): bool => $rule->targetType === RuleTargetType::TYPE,
        ));
    }
}
