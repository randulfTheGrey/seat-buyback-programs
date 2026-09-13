<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Compression;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionDataHealth;

final class CompressionHealthService
{
    public function __construct(private readonly CompressionProjectionStore $store)
    {
    }

    public function current(): CompressionDataHealth
    {
        return $this->store->health();
    }
}
