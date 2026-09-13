<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Compression;

use JsonException;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionCandidate;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionPair;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;
use ZipArchive;

final class CompressionArchiveParser
{
    private const DATASET_MEMBER = 'compressibleTypes.jsonl';

    public function parse(string $archivePath): CompressionCandidate
    {
        $archive = new ZipArchive();
        $openResult = $archive->open($archivePath);

        if ($openResult !== true) {
            throw new CompressionSyncException(
                CompressionSyncFailureCode::ARCHIVE_FAILURE,
                'The downloaded CCP SDE artifact is not a readable ZIP archive.',
            );
        }

        $stream = $archive->getStream(self::DATASET_MEMBER);

        if ($stream === false) {
            $archive->close();

            throw new CompressionSyncException(
                CompressionSyncFailureCode::ARCHIVE_FAILURE,
                'The CCP SDE archive does not contain compressibleTypes.jsonl.',
            );
        }

        $pairs = [];
        $lineNumber = 0;

        try {
            while (($line = fgets($stream)) !== false) {
                $lineNumber++;

                if (trim($line) === '') {
                    continue;
                }

                try {
                    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new CompressionSyncException(
                        CompressionSyncFailureCode::PARSE_FAILURE,
                        sprintf('compressibleTypes.jsonl contains invalid JSON on line %d.', $lineNumber),
                        $exception,
                    );
                }

                if (! is_array($record)
                    || ! array_key_exists('_key', $record)
                    || ! array_key_exists('compressedTypeID', $record)) {
                    throw new CompressionSyncException(
                        CompressionSyncFailureCode::PARSE_FAILURE,
                        sprintf('compressibleTypes.jsonl has an invalid record shape on line %d.', $lineNumber),
                    );
                }

                $pairs[] = new CompressionPair($record['_key'], $record['compressedTypeID']);
            }
        } finally {
            fclose($stream);
            $archive->close();
        }

        return new CompressionCandidate($pairs);
    }
}
