<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum AppraisalLineStatus: string
{
    case PRICED = 'PRICED';
    case EXCLUDED = 'EXCLUDED';
    case UNPRICED = 'UNPRICED';
    case REFERENCE_UNAVAILABLE = 'REFERENCE_UNAVAILABLE';
    case UNKNOWN = 'UNKNOWN';
    case INVALID_INPUT = 'INVALID_INPUT';
}
