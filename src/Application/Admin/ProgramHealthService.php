<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin\ProgramHealth;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionDataHealth;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionDataStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class ProgramHealthService
{
    /** @var array<int, true>|null */
    private ?array $providerIds = null;

    private ?CompressionDataHealth $compression = null;

    public function __construct(
        private readonly ProgramConfigurationValidator $validator,
        private readonly CompressionHealthService $compressionHealth,
    ) {
    }

    public function forProgram(BuybackProgram $program): ProgramHealth
    {
        $program->loadMissing(['priceReferences', 'rules']);
        $compression = $this->compression ??= $this->compressionHealth->current();
        $configuration = $this->validator->validate($program, $compression);
        $references = [];
        $runtimeErrors = [];
        $warnings = $configuration->warnings;
        $models = $program->priceReferences->keyBy(
            static fn ($reference): string => $reference->reference_mode->value,
        );

        foreach ([ReferenceMode::BUY, ReferenceMode::SELL] as $mode) {
            $model = $models->get($mode->value);
            $healthy = $model !== null
                && $model->resolution === ReferenceResolution::PROVIDER
                && $model->provider_instance_id !== null
                && isset($this->providerIds()[(int) $model->provider_instance_id]);
            $message = match (true) {
                $model === null || $model->provider_instance_id === null => 'not configured',
                ! isset($this->providerIds()[(int) $model->provider_instance_id]) => 'invalid — configured provider instance no longer exists',
                default => 'healthy',
            };
            $references[$mode->value] = ['healthy' => $healthy, 'message' => $message];
        }

        $split = $models->get(ReferenceMode::SPLIT->value);
        $splitHealthy = false;
        $splitMessage = 'not configured';

        if ($split?->resolution === ReferenceResolution::DERIVED_MIDPOINT) {
            $splitHealthy = $references['BUY']['healthy'] && $references['SELL']['healthy'];
            $splitMessage = $splitHealthy
                ? 'healthy — derived midpoint'
                : 'unavailable — derived SPLIT requires healthy BUY and SELL references';
        } elseif ($split?->resolution === ReferenceResolution::PROVIDER) {
            $splitHealthy = $split->provider_instance_id !== null
                && isset($this->providerIds()[(int) $split->provider_instance_id]);
            $splitMessage = match (true) {
                $split->provider_instance_id === null => 'not configured',
                ! $splitHealthy => 'invalid — configured provider instance no longer exists',
                default => 'healthy — dedicated provider',
            };
        }

        $references['SPLIT'] = ['healthy' => $splitHealthy, 'message' => $splitMessage];
        $requiredModes = [(string) $program->default_reference_mode->value => true];

        foreach ($program->rules as $rule) {
            if ($rule->enabled && $rule->archived_at === null && $rule->reference_mode_override !== null) {
                $requiredModes[$rule->reference_mode_override->value] = true;
            }
        }

        foreach (array_keys($requiredModes) as $mode) {
            if (! ($references[$mode]['healthy'] ?? false)) {
                $runtimeErrors[] = sprintf('%s reference is unavailable: %s.', $mode, $references[$mode]['message'] ?? 'not configured');
            }
        }

        $compressionRequired = $program->rules->contains(static fn ($rule): bool =>
            $rule->enabled
            && $rule->archived_at === null
            && $rule->target_type === RuleTargetType::GROUP
            && $rule->compression_qualifier !== CompressionQualifier::ANY);
        if ($compressionRequired && ! $compression->usable) {
            $runtimeErrors[] = 'Compression reference data is missing but an enabled compression-qualified rule requires it.';
        } elseif ($compression->status === CompressionDataStatus::STALE) {
            $warnings[] = 'Compression reference data is stale. Using last-known-good mapping.';
        }

        $buy = $models->get('BUY')?->provider_instance_id;
        $sell = $models->get('SELL')?->provider_instance_id;

        if ($buy !== null && $sell !== null && (int) $buy === (int) $sell) {
            $warnings[] = 'BUY and SELL currently use the same provider instance. Derived SPLIT will equal that stream.';
        }

        return new ProgramHealth(
            $configuration,
            $references,
            array_values(array_unique($runtimeErrors)),
            array_values(array_unique($warnings)),
        );
    }

    /** @return array<int, true> */
    private function providerIds(): array
    {
        return $this->providerIds ??= array_fill_keys(
            PriceProviderInstance::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            true,
        );
    }
}
