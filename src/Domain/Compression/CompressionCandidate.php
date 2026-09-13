<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression;

final readonly class CompressionCandidate
{
    /**
     * @param list<CompressionPair> $pairs
     */
    public function __construct(public array $pairs)
    {
    }
}
