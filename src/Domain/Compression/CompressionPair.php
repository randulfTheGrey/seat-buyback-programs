<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression;

final readonly class CompressionPair
{
    public function __construct(
        public mixed $uncompressedTypeId,
        public mixed $compressedTypeId,
    ) {
    }
}
