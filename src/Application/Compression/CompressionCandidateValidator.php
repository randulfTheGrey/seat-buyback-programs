<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Compression;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionCandidate;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;

final class CompressionCandidateValidator
{
    public function validate(CompressionCandidate $candidate): void
    {
        if ($candidate->pairs === []) {
            $this->fail('The compressibleTypes candidate dataset is empty.');
        }

        $uncompressed = [];
        $compressed = [];

        foreach ($candidate->pairs as $index => $pair) {
            $record = $index + 1;

            if (! is_int($pair->uncompressedTypeId) || $pair->uncompressedTypeId <= 0) {
                $this->fail(sprintf('Candidate record %d has an invalid _key.', $record));
            }

            if (! is_int($pair->compressedTypeId) || $pair->compressedTypeId <= 0) {
                $this->fail(sprintf('Candidate record %d has an invalid compressedTypeID.', $record));
            }

            if ($pair->uncompressedTypeId === $pair->compressedTypeId) {
                $this->fail(sprintf('Candidate record %d maps a type ID to itself.', $record));
            }

            if (isset($uncompressed[$pair->uncompressedTypeId])) {
                $this->fail(sprintf(
                    'Candidate contains duplicate uncompressed type ID %d.',
                    $pair->uncompressedTypeId,
                ));
            }

            if (isset($compressed[$pair->compressedTypeId])) {
                $this->fail(sprintf(
                    'Candidate contains duplicate compressed type ID %d.',
                    $pair->compressedTypeId,
                ));
            }

            $uncompressed[$pair->uncompressedTypeId] = true;
            $compressed[$pair->compressedTypeId] = true;
        }

        $overlap = array_key_first(array_intersect_key($uncompressed, $compressed));

        if ($overlap !== null) {
            $this->fail(sprintf(
                'Candidate type ID %d appears on both sides of the compression relationship.',
                $overlap,
            ));
        }
    }

    private function fail(string $message): never
    {
        throw new CompressionSyncException(
            CompressionSyncFailureCode::VALIDATION_FAILURE,
            $message,
        );
    }
}
