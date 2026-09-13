<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ModifierOperation;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use Throwable;

abstract class RuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('program');
        $rule = $this->route('rule');

        if (! $program instanceof BuybackProgram) {
            return false;
        }

        if ($rule instanceof BuybackRule) {
            return (int) $rule->program_id === (int) $program->id
                && $this->user()?->can('update', $rule) === true;
        }

        return $this->user()?->can('create', BuybackRule::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::enum(RuleTargetType::class)],
            'target_id' => ['required', 'integer', 'min:1'],
            'compression_qualifier' => ['required', Rule::enum(CompressionQualifier::class)],
            'acceptance' => ['required', Rule::enum(RuleAcceptance::class)],
            'reference_mode_override' => ['required', Rule::in(array_merge(
                ['INHERIT'],
                array_column(ReferenceMode::cases(), 'value'),
            ))],
            'modifier_operation' => ['required', Rule::enum(ModifierOperation::class)],
            'modifier_direction' => ['nullable', Rule::in(['discount', 'premium', 'none'])],
            'modifier_percentage' => ['nullable', 'string', 'max:7'],
            'rule_status' => ['required', Rule::in(['ENABLED', 'DISABLED', 'ARCHIVED'])],
            'admin_note' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $existingRule = $this->route('rule');

            if (
                (string) $this->input('target_type') === RuleTargetType::TYPE->value
                && (string) $this->input('compression_qualifier') !== CompressionQualifier::ANY->value
            ) {
                $validator->errors()->add(
                    'compression_qualifier',
                    'Item Type rules use one exact-TypeID layer; compression qualification applies only to Group rules.',
                );
            }

            if (
                (string) $this->input('rule_status') === 'ARCHIVED'
                && (! $existingRule instanceof \RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule || $existingRule->archived_at === null)
            ) {
                $validator->errors()->add('rule_status', 'Use the dedicated archive action so deactivation is explicit.');
            }

            if ($this->input('modifier_operation') === ModifierOperation::INHERIT->value) {
                return;
            }

            try {
                AdminUi::modifierBps(
                    (string) $this->input('modifier_direction'),
                    (string) $this->input('modifier_percentage', '0'),
                );
            } catch (Throwable $exception) {
                $validator->errors()->add('modifier_percentage', $exception->getMessage());
            }
        });
    }

    /** @return array<string, mixed> */
    public function ruleAttributes(): array
    {
        $operation = ModifierOperation::from((string) $this->validated('modifier_operation'));
        $status = (string) $this->validated('rule_status');
        $reference = (string) $this->validated('reference_mode_override');
        $adminNote = trim((string) ($this->validated('admin_note') ?? ''));

        return [
            'target_type' => RuleTargetType::from((string) $this->validated('target_type')),
            'target_id' => (int) $this->validated('target_id'),
            'compression_qualifier' => CompressionQualifier::from((string) $this->validated('compression_qualifier')),
            'acceptance' => RuleAcceptance::from((string) $this->validated('acceptance')),
            'reference_mode_override' => $reference === 'INHERIT' ? null : ReferenceMode::from($reference),
            'modifier_operation' => $operation,
            'modifier_bps' => $operation === ModifierOperation::INHERIT
                ? null
                : AdminUi::modifierBps(
                    (string) $this->validated('modifier_direction'),
                    (string) ($this->validated('modifier_percentage') ?? '0'),
                ),
            'enabled' => $status === 'ENABLED',
            'archived_at' => $status === 'ARCHIVED' ? now() : null,
            'admin_note' => $adminNote === '' ? null : $adminNote,
        ];
    }
}
