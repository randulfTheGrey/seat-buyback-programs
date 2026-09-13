<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum ModifierOperation: string
{
    case INHERIT = 'INHERIT';
    case REPLACE = 'REPLACE';
    case ADJUST = 'ADJUST';
}
