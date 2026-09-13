<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Contracts;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionSyncResult;

interface CompressionSync
{
    public function synchronize(): CompressionSyncResult;
}
