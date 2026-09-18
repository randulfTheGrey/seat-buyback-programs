<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Exceptions;

use DomainException;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\EffectiveModifierViolation;
use Throwable;

final class InvalidEffectivePolicyException extends DomainException
{
    public function __construct(
        public readonly EffectiveModifierViolation $violation,
        ?Throwable $previous = null,
    ) {
        parent::__construct($violation->debugMessage(), previous: $previous);
    }
}
