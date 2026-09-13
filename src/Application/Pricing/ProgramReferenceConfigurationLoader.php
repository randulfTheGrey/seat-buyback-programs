<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Pricing;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProgramReference;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing\ProgramReferenceConfiguration;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class ProgramReferenceConfigurationLoader
{
    public function forProgram(BuybackProgram $program): ProgramReferenceConfiguration
    {
        $references = [];

        foreach ($program->priceReferences()->get() as $model) {
            $mode = ReferenceMode::tryFrom((string) $model->getRawOriginal('reference_mode'));
            $resolution = ReferenceResolution::tryFrom((string) $model->getRawOriginal('resolution'));

            if ($mode === null || $resolution === null) {
                continue;
            }

            $providerId = $model->getRawOriginal('provider_instance_id');
            $references[] = new ProgramReference(
                $mode,
                $resolution,
                $providerId === null ? null : (int) $providerId,
            );
        }

        return new ProgramReferenceConfiguration($references);
    }
}
