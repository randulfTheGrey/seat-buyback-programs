<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum PolicyLayerSource: string
{
    case PROGRAM_DEFAULTS = 'PROGRAM_DEFAULTS';
    case GROUP = 'GROUP';
    case GROUP_COMPRESSION = 'GROUP_COMPRESSION';
    case TYPE = 'TYPE';
}
