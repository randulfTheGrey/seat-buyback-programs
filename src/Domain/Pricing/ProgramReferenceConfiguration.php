<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Pricing;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;

final readonly class ProgramReferenceConfiguration
{
    /** @var array<string, ProgramReference> */
    private array $references;

    /** @param iterable<ProgramReference> $references */
    public function __construct(iterable $references)
    {
        $indexed = [];

        foreach ($references as $reference) {
            $indexed[$reference->mode->value] = $reference;
        }

        $this->references = $indexed;
    }

    public function get(ReferenceMode $mode): ?ProgramReference
    {
        return $this->references[$mode->value] ?? null;
    }
}
