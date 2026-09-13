<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Compression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionCandidateValidator;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionCandidate;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionPair;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;

final class CompressionCandidateValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{list<CompressionPair>, string}>
     */
    public static function invalidCandidateProvider(): iterable
    {
        yield 'empty dataset' => [[], 'empty'];
        yield 'invalid uncompressed ID' => [[new CompressionPair(0, 100)], 'invalid _key'];
        yield 'string uncompressed ID' => [[new CompressionPair('18', 100)], 'invalid _key'];
        yield 'invalid compressed ID' => [[new CompressionPair(18, -1)], 'invalid compressedTypeID'];
        yield 'same ID pair' => [[new CompressionPair(18, 18)], 'maps a type ID to itself'];
        yield 'duplicate uncompressed ID' => [[
            new CompressionPair(18, 100),
            new CompressionPair(18, 101),
        ], 'duplicate uncompressed type ID 18'];
        yield 'duplicate compressed ID' => [[
            new CompressionPair(18, 100),
            new CompressionPair(19, 100),
        ], 'duplicate compressed type ID 100'];
        yield 'ID appears on both sides' => [[
            new CompressionPair(18, 100),
            new CompressionPair(100, 200),
        ], 'appears on both sides'];
    }

    /**
     * @param list<CompressionPair> $pairs
     */
    #[DataProvider('invalidCandidateProvider')]
    public function test_invalid_candidate_is_rejected(array $pairs, string $expectedMessage): void
    {
        try {
            (new CompressionCandidateValidator())->validate(new CompressionCandidate($pairs));
            self::fail('Expected candidate validation to fail.');
        } catch (CompressionSyncException $exception) {
            self::assertSame(CompressionSyncFailureCode::VALIDATION_FAILURE, $exception->failureCode);
            self::assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    public function test_valid_one_to_one_candidate_is_accepted(): void
    {
        (new CompressionCandidateValidator())->validate(new CompressionCandidate([
            new CompressionPair(99000001, 99100001),
            new CompressionPair(99000002, 99100002),
        ]));

        self::addToAssertionCount(1);
    }
}
