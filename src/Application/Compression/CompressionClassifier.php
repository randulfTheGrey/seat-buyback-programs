<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Compression;

use InvalidArgumentException;
use RuntimeException;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;

final class CompressionClassifier
{
    public function classify(int $typeId): CompressionState
    {
        return $this->classifyBatch([$typeId])[$typeId];
    }

    /**
     * @param iterable<int> $typeIds
     * @return array<int, CompressionState>
     */
    public function classifyBatch(iterable $typeIds): array
    {
        $classifications = [];

        foreach ($typeIds as $typeId) {
            if (! is_int($typeId) || $typeId <= 0) {
                throw new InvalidArgumentException('Compression classification requires positive integer type IDs.');
            }

            $classifications[$typeId] = CompressionState::NOT_APPLICABLE;
        }

        if ($classifications === []) {
            return [];
        }

        $ids = array_keys($classifications);
        $mappings = CompressionMapping::query()
            ->whereIn('uncompressed_type_id', $ids)
            ->orWhereIn('compressed_type_id', $ids)
            ->get(['uncompressed_type_id', 'compressed_type_id']);

        $uncompressed = [];
        $compressed = [];

        foreach ($mappings as $mapping) {
            $uncompressed[$mapping->uncompressed_type_id] = true;
            $compressed[$mapping->compressed_type_id] = true;
        }

        $overlap = array_key_first(array_intersect_key($uncompressed, $compressed));

        if ($overlap !== null) {
            throw new RuntimeException(sprintf(
                'Invalid compression reference data: type ID %d appears on both mapping sides.',
                $overlap,
            ));
        }

        foreach ($classifications as $typeId => $unused) {
            $classifications[$typeId] = match (true) {
                isset($uncompressed[$typeId]) => CompressionState::UNCOMPRESSED,
                isset($compressed[$typeId]) => CompressionState::COMPRESSED,
                default => CompressionState::NOT_APPLICABLE,
            };
        }

        return $classifications;
    }
}
