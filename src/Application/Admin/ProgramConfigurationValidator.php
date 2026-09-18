<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Illuminate\Database\Eloquent\Builder;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin\ConfigurationValidationResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin\EffectivePolicyDiagnostic;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionDataHealth;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ItemPolicyContext;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyEvaluator;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidEffectivePolicyException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Persistence\PolicyInputMapper;
use Throwable;

final class ProgramConfigurationValidator
{
    public function __construct(
        private readonly PolicyInputMapper $mapper,
        private readonly PolicyEvaluator $evaluator,
        private readonly CompressionTargetApplicability $compressionApplicability,
    ) {
    }

    public function validate(
        BuybackProgram $program,
        ?CompressionDataHealth $compressionHealth = null,
    ): ConfigurationValidationResult {
        $generalErrors = [];
        $warnings = [];

        if (trim((string) $program->name) === '') {
            $generalErrors[] = 'Program name is required.';
        }

        if ((int) $program->quote_validity_minutes < 1) {
            $generalErrors[] = 'Quote validity must be at least one minute.';
        }

        try {
            $defaults = $this->mapper->defaults($program);
            $program->loadMissing('rules');
            $rules = $this->mapper->activeRules($program->rules);
        } catch (Throwable $exception) {
            $generalErrors[] = 'Stored Program policy is invalid: ' . $exception->getMessage();

            return new ConfigurationValidationResult(
                generalErrors: array_values(array_unique($generalErrors)),
                warnings: $warnings,
            );
        }

        if (
            $defaults->acceptance === Acceptance::REJECT
            && ! collect($rules)->contains(static fn ($rule): bool => $rule->acceptance === RuleAcceptance::ACCEPT)
        ) {
            $warnings[] = 'This Program currently rejects every item.';
        }

        if ($rules === []) {
            return new ConfigurationValidationResult(
                generalErrors: array_values(array_unique($generalErrors)),
                warnings: array_values(array_unique($warnings)),
            );
        }

        $groupTargetIds = collect($rules)
            ->where('targetType', RuleTargetType::GROUP)
            ->pluck('targetId')
            ->unique()
            ->values()
            ->all();
        $typeTargetIds = collect($rules)
            ->where('targetType', RuleTargetType::TYPE)
            ->pluck('targetId')
            ->unique()
            ->values()
            ->all();
        $qualifiedGroupIds = collect($rules)
            ->filter(static fn ($rule): bool =>
                $rule->targetType === RuleTargetType::GROUP
                && $rule->compressionQualifier !== CompressionQualifier::ANY)
            ->pluck('targetId')
            ->unique()
            ->values()
            ->all();
        $groupQualifiers = $compressionHealth?->usable && $qualifiedGroupIds !== []
            ? $this->compressionApplicability->qualifiersForGroups($qualifiedGroupIds)
            : [];

        foreach ($rules as $rule) {
            if (
                $rule->targetType === RuleTargetType::GROUP
                && $rule->compressionQualifier !== CompressionQualifier::ANY
                && isset($groupQualifiers[$rule->targetId])
                && ! in_array($rule->compressionQualifier, $groupQualifiers[$rule->targetId], true)
            ) {
                $generalErrors[] = sprintf(
                    'Enabled GROUP rule #%d targets a group with no %s canonical members.',
                    $rule->ruleId,
                    $rule->compressionQualifier->value,
                );
            }
        }

        $types = InvType::query()
            ->where('published', true)
            ->where(function (Builder $query) use ($groupTargetIds, $typeTargetIds): void {
                if ($groupTargetIds !== []) {
                    $query->whereIn('groupID', $groupTargetIds);
                }

                if ($typeTargetIds !== []) {
                    $method = $groupTargetIds === [] ? 'whereIn' : 'orWhereIn';
                    $query->{$method}('typeID', $typeTargetIds);
                }
            })
            ->get(['typeID', 'groupID', 'typeName']);
        $foundTypeIds = $types->pluck('typeID')->map(static fn ($id): int => (int) $id)->all();

        foreach (array_diff($typeTargetIds, $foundTypeIds) as $missingTypeId) {
            $generalErrors[] = sprintf('An enabled Item Type rule references missing SDE type #%d.', $missingTypeId);
        }

        $groupNames = InvGroup::query()
            ->whereIn('groupID', $groupTargetIds)
            ->pluck('groupName', 'groupID')
            ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
        $typeNames = $types
            ->mapWithKeys(static fn ($type): array => [(int) $type->typeID => (string) $type->typeName])
            ->all();
        $states = $compressionHealth?->usable
            ? $this->compressionApplicability->statesForTypes($foundTypeIds)
            : [];
        $typeRuleIds = array_fill_keys($typeTargetIds, true);
        $contexts = [];

        foreach ($types as $type) {
            $typeId = (int) $type->typeID;
            $groupId = (int) $type->groupID;
            $state = $states[$typeId] ?? CompressionState::NOT_APPLICABLE;
            $identity = isset($typeRuleIds[$typeId])
                ? 'type:' . $typeId
                : implode(':', ['group', (string) $groupId, $state->value]);
            $contexts[$identity] = new ItemPolicyContext($typeId, $groupId, $state);
        }

        /** @var array<string, EffectivePolicyDiagnostic> $diagnostics */
        $diagnostics = [];

        foreach ($contexts as $context) {
            try {
                $this->evaluator->evaluate($defaults, $context, $rules);
            } catch (InvalidEffectivePolicyException $exception) {
                $violation = $exception->violation;
                $targetName = $violation->targetType === RuleTargetType::TYPE
                    ? ($typeNames[$violation->targetId] ?? sprintf('Type #%d', $violation->targetId))
                    : ($groupNames[$violation->targetId] ?? sprintf('Group #%d', $violation->targetId));
                $diagnostic = new EffectivePolicyDiagnostic($violation, $targetName);
                $existing = $diagnostics[$diagnostic->identity()] ?? null;

                if ($existing === null || $diagnostic->violation->excessBps() > $existing->violation->excessBps()) {
                    $diagnostics[$diagnostic->identity()] = $diagnostic;
                }
            } catch (Throwable $exception) {
                $generalErrors[] = 'Effective policy is invalid: ' . $exception->getMessage();
            }
        }

        return new ConfigurationValidationResult(
            generalErrors: array_values(array_unique($generalErrors)),
            effectivePolicyDiagnostics: array_values($diagnostics),
            warnings: array_values(array_unique($warnings)),
        );
    }
}
