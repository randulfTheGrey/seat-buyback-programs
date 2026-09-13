<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum ReferencePriceStatus: string
{
    case PRICED = 'PRICED';
    case UNPRICED = 'UNPRICED';
    case REFERENCE_UNAVAILABLE = 'REFERENCE_UNAVAILABLE';
    case REFERENCE_MISCONFIGURED = 'REFERENCE_MISCONFIGURED';
}
