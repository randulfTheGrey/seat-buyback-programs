<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal;

final readonly class CompletedAppraisal
{
    public function __construct(
        public string $token,
        public AppraisalResult $result,
    ) {
    }
}
