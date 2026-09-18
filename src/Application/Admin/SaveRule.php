<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyRule;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use Throwable;

final class SaveRule
{
    public function __construct(
        private readonly CompressionTargetApplicability $compressionApplicability,
        private readonly ProgramHealthService $programHealth,
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public function save(
        BuybackProgram $program,
        ?BuybackRule $rule,
        array $attributes,
        int $actorUserId,
    ): BuybackRule {
        return DB::transaction(function () use ($program, $rule, $attributes, $actorUserId): BuybackRule {
            $creating = $rule === null;
            $rule ??= new BuybackRule();

            if (! $creating && (int) $rule->program_id !== (int) $program->id) {
                abort(404);
            }

            $this->validateTarget($attributes);
            $this->validatePolicyShape($attributes, $rule);
            $this->validateUniqueIdentity($program, $attributes, $rule);

            $rule->fill($attributes);
            $rule->program_id = $program->id;
            $rule->updated_by_user_id = $actorUserId;

            if ($creating) {
                $rule->created_by_user_id = $actorUserId;
            }

            try {
                $rule->save();
            } catch (QueryException $exception) {
                throw ValidationException::withMessages([
                    'target_id' => $attributes['target_type'] === RuleTargetType::TYPE
                        ? 'A rule already exists for this Program and Item Type. Edit or restore the existing rule.'
                        : 'A rule already exists for this Program, Group, and compression qualifier. Edit or restore the existing rule.',
                ]);
            }

            $program->unsetRelations();
            $health = $this->programHealth->forProgram($program);

            if ((bool) $attributes['enabled'] && ! $health->configuration->valid()) {
                throw ValidationException::withMessages([
                    'rule_status' => array_merge(
                        ['This enabled rule would make the Program policy configuration invalid.'],
                        $health->configuration->errors,
                    ),
                ]);
            }

            if ($program->status === ProgramStatus::ENABLED && $health->runtimeErrors !== []) {
                throw ValidationException::withMessages([
                    'rule_status' => array_merge(
                        ['This rule would make the enabled Program operationally invalid.'],
                        $health->runtimeErrors,
                    ),
                ]);
            }

            return $rule;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function validateTarget(array $attributes): void
    {
        $type = $attributes['target_type'];
        $id = (int) $attributes['target_id'];

        if ($type === RuleTargetType::TYPE) {
            $exists = InvType::query()->where('published', true)->whereKey($id)->exists();

            if (! $exists) {
                throw ValidationException::withMessages(['target_id' => 'Select a published EVE item type from the search results.']);
            }

            if ($attributes['compression_qualifier'] !== CompressionQualifier::ANY) {
                throw ValidationException::withMessages([
                    'compression_qualifier' => 'Item Type rules have one exact-TypeID identity; compression qualification applies only to Group rules.',
                ]);
            }

            return;
        }

        if ($type === RuleTargetType::GROUP && InvGroup::query()->whereKey($id)->exists()) {
            $available = $this->compressionApplicability->qualifiersForGroups([$id])[$id];

            if ($available !== null && ! in_array($attributes['compression_qualifier'], $available, true)) {
                throw ValidationException::withMessages([
                    'compression_qualifier' => sprintf(
                        'CCP SDE does not classify any %s item in this group. Choose an available qualifier.',
                        strtolower($attributes['compression_qualifier']->value),
                    ),
                ]);
            }

            return;
        }

        throw ValidationException::withMessages(['target_id' => 'Select an EVE item group from the search results.']);
    }

    /** @param array<string, mixed> $attributes */
    private function validatePolicyShape(array $attributes, BuybackRule $rule): void
    {
        try {
            new PolicyRule(
                $rule->exists ? (int) $rule->id : null,
                $attributes['target_type'],
                (int) $attributes['target_id'],
                $attributes['compression_qualifier'],
                $attributes['acceptance'],
                $attributes['reference_mode_override'],
                $attributes['modifier_operation'],
                $attributes['modifier_bps'] === null
                    ? null
                    : new \RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints((int) $attributes['modifier_bps']),
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['acceptance' => $exception->getMessage()]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function validateUniqueIdentity(BuybackProgram $program, array $attributes, BuybackRule $rule): void
    {
        $duplicate = $program->rules()
            ->where('target_type', $attributes['target_type']->value)
            ->where('target_id', (int) $attributes['target_id'])
            ->when(
                $attributes['target_type'] === RuleTargetType::GROUP,
                static fn ($query) => $query->where(
                    'compression_qualifier',
                    $attributes['compression_qualifier']->value,
                ),
            )
            ->when($rule->exists, static fn ($query) => $query->whereKeyNot($rule->getKey()))
            ->first();

        if ($duplicate === null) {
            return;
        }

        $message = $attributes['target_type'] === RuleTargetType::TYPE
            ? 'Rule #%d already covers this Program and Item Type. Edit or restore that rule.'
            : 'Rule #%d already covers this Program, Group, and compression qualifier. Edit or restore that rule.';

        throw ValidationException::withMessages([
            'target_id' => sprintf($message, $duplicate->id),
        ]);
    }
}
