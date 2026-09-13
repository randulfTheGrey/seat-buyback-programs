<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin\ConfigurationValidationResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionDataHealth;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ItemPolicyContext;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyEvaluator;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\Acceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleAcceptance;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use RandulfTheGrey\Seat\BuybackPrograms\Persistence\PolicyInputMapper;
use Throwable;

final class ProgramConfigurationValidator
{
    /** @var array<int, int>|null */
    private ?array $typeGroupIds = null;

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
        $errors = [];
        $warnings = [];

        if (trim((string) $program->name) === '') {
            $errors[] = 'Program name is required.';
        }

        if ((int) $program->quote_validity_minutes < 1) {
            $errors[] = 'Quote validity must be at least one minute.';
        }

        try {
            $defaults = $this->mapper->defaults($program);
            $program->loadMissing('rules');
            $rules = $this->mapper->activeRules($program->rules);
        } catch (Throwable $exception) {
            $errors[] = 'Stored Program policy is invalid: ' . $exception->getMessage();

            return new ConfigurationValidationResult(array_values(array_unique($errors)), $warnings);
        }

        if (
            $defaults->acceptance === Acceptance::REJECT
            && ! collect($rules)->contains(static fn ($rule): bool => $rule->acceptance === RuleAcceptance::ACCEPT)
        ) {
            $warnings[] = 'This Program currently rejects every item.';
        }

        $typeGroupIds = $this->typeGroupIds();
        $qualifiedGroupIds = collect($rules)
            ->filter(static fn ($rule): bool =>
                $rule->targetType === RuleTargetType::GROUP
                && $rule->compressionQualifier !== CompressionQualifier::ANY)
            ->pluck('targetId')
            ->all();
        $groupQualifiers = $compressionHealth?->usable && $qualifiedGroupIds !== []
            ? $this->compressionApplicability->qualifiersForGroups($qualifiedGroupIds)
            : [];

        foreach ($rules as $rule) {
            $groupId = $rule->targetType === RuleTargetType::GROUP
                ? $rule->targetId
                : (int) ($typeGroupIds[$rule->targetId] ?? 0);

            if ($groupId <= 0) {
                $errors[] = sprintf('Enabled TYPE rule #%d references a missing SDE type.', $rule->ruleId);
                continue;
            }

            if (
                $rule->targetType === RuleTargetType::GROUP
                && $rule->compressionQualifier !== CompressionQualifier::ANY
                && isset($groupQualifiers[$rule->targetId])
                && ! in_array($rule->compressionQualifier, $groupQualifiers[$rule->targetId], true)
            ) {
                $errors[] = sprintf(
                    'Enabled GROUP rule #%d targets a group with no %s canonical members.',
                    $rule->ruleId,
                    $rule->compressionQualifier->value,
                );
            }

            foreach ([CompressionState::NOT_APPLICABLE, CompressionState::UNCOMPRESSED, CompressionState::COMPRESSED] as $state) {
                try {
                    $this->evaluator->evaluate(
                        $defaults,
                        new ItemPolicyContext(
                            $rule->targetType === RuleTargetType::TYPE ? $rule->targetId : PHP_INT_MAX,
                            $groupId,
                            $state,
                        ),
                        $rules,
                    );
                } catch (Throwable $exception) {
                    $errors[] = 'Effective policy is invalid: ' . $exception->getMessage();
                }
            }
        }

        return new ConfigurationValidationResult(
            array_values(array_unique($errors)),
            array_values(array_unique($warnings)),
        );
    }

    /** @return array<int, int> */
    private function typeGroupIds(): array
    {
        if ($this->typeGroupIds !== null) {
            return $this->typeGroupIds;
        }

        $ids = BuybackRule::query()
            ->where('target_type', RuleTargetType::TYPE->value)
            ->where('enabled', true)
            ->whereNull('archived_at')
            ->distinct()
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return $this->typeGroupIds = [];
        }

        return $this->typeGroupIds = InvType::query()
            ->whereIn('typeID', $ids)
            ->pluck('groupID', 'typeID')
            ->mapWithKeys(static fn ($groupId, $typeId): array => [(int) $typeId => (int) $groupId])
            ->all();
    }
}
