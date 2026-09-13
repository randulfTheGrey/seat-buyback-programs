<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Enums;

enum InventoryInputError: string
{
    case MISSING_QUANTITY = 'MISSING_QUANTITY';
    case MALFORMED_QUANTITY = 'MALFORMED_QUANTITY';
    case NON_POSITIVE_QUANTITY = 'NON_POSITIVE_QUANTITY';
    case MISSING_ITEM_NAME = 'MISSING_ITEM_NAME';
    case QUANTITY_OVERFLOW = 'QUANTITY_OVERFLOW';
}
