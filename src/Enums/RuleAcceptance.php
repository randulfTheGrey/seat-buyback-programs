<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum RuleAcceptance: string
{
    case INHERIT = 'INHERIT';
    case ACCEPT = 'ACCEPT';
    case REJECT = 'REJECT';
}
