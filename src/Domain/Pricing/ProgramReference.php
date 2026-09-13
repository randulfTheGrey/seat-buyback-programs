<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;

final readonly class ProgramReference
{
    public function __construct(
        public ReferenceMode $mode,
        public ReferenceResolution $resolution,
        public ?int $providerInstanceId,
    ) {
    }
}
