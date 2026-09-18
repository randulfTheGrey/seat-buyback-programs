<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin\ProgramHealth;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class SaveProgramConfiguration
{
    public function __construct(private readonly ProgramHealthService $health)
    {
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, array<string, mixed>> $references
     * @return array{0: BuybackProgram, 1: ProgramHealth}
     */
    public function save(
        ?BuybackProgram $program,
        array $attributes,
        array $references,
        int $actorUserId,
    ): array {
        return DB::transaction(function () use ($program, $attributes, $references, $actorUserId): array {
            $creating = $program === null;
            $wasEnabled = ! $creating && $program->status === ProgramStatus::ENABLED;
            $beforeHealth = ! $creating
                ? $this->health->forProgram($program)
                : null;
            $program ??= new BuybackProgram();

            if (
                ! $creating
                && $program->status === ProgramStatus::ARCHIVED
                && $attributes['status'] !== ProgramStatus::ARCHIVED
            ) {
                throw ValidationException::withMessages([
                    'status' => 'Archived Programs cannot be reactivated. Create a new Program if policy should resume.',
                ]);
            }

            $program->fill($attributes);
            $program->updated_by_user_id = $actorUserId;

            if ($creating) {
                $program->created_by_user_id = $actorUserId;
            }

            $program->save();

            foreach ($references as $mode => $reference) {
                $program->priceReferences()->updateOrCreate(
                    ['reference_mode' => $mode],
                    $reference,
                );
            }

            $program->unsetRelations();
            $result = $this->health->forProgram($program);

            $newRuntimeErrors = $wasEnabled && $beforeHealth !== null
                ? array_values(array_diff($result->runtimeErrors, $beforeHealth->runtimeErrors))
                : $result->runtimeErrors;
            $blockingConfigurationErrors = $result->configuration
                ->blockingErrorsComparedTo($beforeHealth?->configuration);

            if ($blockingConfigurationErrors !== []) {
                throw ValidationException::withMessages([
                    'status' => array_merge(
                        ['Program was not saved because the proposed configuration introduces or worsens an invalid policy.'],
                        $blockingConfigurationErrors,
                    ),
                ]);
            }

            if (
                $program->status === ProgramStatus::ENABLED
                && $newRuntimeErrors !== []
            ) {
                throw ValidationException::withMessages([
                    'status' => array_merge(
                        ['Program cannot be enabled.'],
                        $newRuntimeErrors,
                    ),
                ]);
            }

            return [$program, $result];
        });
    }
}
