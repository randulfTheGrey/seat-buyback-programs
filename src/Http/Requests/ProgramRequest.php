<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi;
use RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use Throwable;

abstract class ProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('program');

        return $program instanceof BuybackProgram
            ? $this->user()?->can('update', $program) === true
            : $this->user()?->can('create', BuybackProgram::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::enum(ProgramStatus::class)],
            'quote_validity_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
            'contract_instructions' => ['nullable', 'string', 'max:20000'],
            'default_acceptance' => ['required', Rule::enum(Acceptance::class)],
            'default_reference_mode' => ['required', Rule::enum(ReferenceMode::class)],
            'default_modifier_direction' => ['required', Rule::in(['discount', 'premium', 'none'])],
            'default_modifier_percentage' => ['nullable', 'string', 'max:7'],
            'buy_provider_instance_id' => ['nullable', 'integer', 'min:1'],
            'sell_provider_instance_id' => ['nullable', 'integer', 'min:1'],
            'split_resolution' => ['required', Rule::enum(ReferenceResolution::class)],
            'split_provider_instance_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_if:split_resolution,' . ReferenceResolution::PROVIDER->value,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $existingProgram = $this->route('program');

            if (
                (string) $this->input('status') === ProgramStatus::ARCHIVED->value
                && (! $existingProgram instanceof BuybackProgram
                    || $existingProgram->status !== ProgramStatus::ARCHIVED)
            ) {
                $validator->errors()->add('status', 'Use the dedicated archive action so the historical-data impact is confirmed.');
            }

            try {
                AdminUi::modifierBps(
                    (string) $this->input('default_modifier_direction'),
                    (string) $this->input('default_modifier_percentage', '0'),
                );
            } catch (Throwable $exception) {
                $validator->errors()->add('default_modifier_percentage', $exception->getMessage());
            }

            $program = $this->route('program');
            $existingProviderIds = $program instanceof BuybackProgram
                ? $program->priceReferences()->whereNotNull('provider_instance_id')->pluck('provider_instance_id')->map(static fn ($id): int => (int) $id)->all()
                : [];

            foreach (['buy_provider_instance_id', 'sell_provider_instance_id', 'split_provider_instance_id'] as $field) {
                $value = $this->input($field);

                if ($value === null || $value === '') {
                    continue;
                }

                $id = (int) $value;

                if (
                    ! in_array($id, $existingProviderIds, true)
                    && ! PriceProviderInstance::query()->whereKey($id)->exists()
                ) {
                    $validator->errors()->add($field, 'Select an existing seat-prices-core provider instance.');
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function programAttributes(ProgramStatus $defaultStatus): array
    {
        return [
            'name' => trim((string) $this->validated('name')),
            'description' => $this->nullableTrimmed('description'),
            'status' => ProgramStatus::from((string) ($this->validated('status') ?? $defaultStatus->value)),
            'quote_validity_minutes' => (int) $this->validated('quote_validity_minutes'),
            'contract_instructions' => $this->nullableTrimmed('contract_instructions'),
            'default_acceptance' => Acceptance::from((string) $this->validated('default_acceptance')),
            'default_reference_mode' => ReferenceMode::from((string) $this->validated('default_reference_mode')),
            'default_modifier_bps' => AdminUi::modifierBps(
                (string) $this->validated('default_modifier_direction'),
                (string) ($this->validated('default_modifier_percentage') ?? '0'),
            ),
        ];
    }

    /** @return array<string, array{resolution: ReferenceResolution, provider_instance_id: ?int}> */
    public function referenceAttributes(): array
    {
        $splitResolution = ReferenceResolution::from((string) $this->validated('split_resolution'));

        return [
            ReferenceMode::BUY->value => [
                'resolution' => ReferenceResolution::PROVIDER,
                'provider_instance_id' => $this->nullableInt('buy_provider_instance_id'),
            ],
            ReferenceMode::SELL->value => [
                'resolution' => ReferenceResolution::PROVIDER,
                'provider_instance_id' => $this->nullableInt('sell_provider_instance_id'),
            ],
            ReferenceMode::SPLIT->value => [
                'resolution' => $splitResolution,
                'provider_instance_id' => $splitResolution === ReferenceResolution::PROVIDER
                    ? $this->nullableInt('split_provider_instance_id')
                    : null,
            ],
        ];
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableTrimmed(string $key): ?string
    {
        $value = trim((string) ($this->validated($key) ?? ''));

        return $value === '' ? null : $value;
    }
}
