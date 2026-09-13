<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Compression;

use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionArchiveParser;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;
use ZipArchive;

final class CompressionArchiveParserTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
    }

    public function test_parses_the_official_compressible_types_jsonl_shape(): void
    {
        $archive = $this->archiveWith(
            'compressibleTypes.jsonl',
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/compression/valid-compressibleTypes.jsonl'),
        );

        $candidate = (new CompressionArchiveParser())->parse($archive);

        self::assertCount(3, $candidate->pairs);
        self::assertSame(99000001, $candidate->pairs[0]->uncompressedTypeId);
        self::assertSame(99100001, $candidate->pairs[0]->compressedTypeId);
    }

    public function test_rejects_an_invalid_archive(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, 'not a zip');

        $this->assertFailure(
            fn () => (new CompressionArchiveParser())->parse($path),
            CompressionSyncFailureCode::ARCHIVE_FAILURE,
            'not a readable ZIP',
        );
    }

    public function test_rejects_an_archive_missing_the_expected_dataset(): void
    {
        $archive = $this->archiveWith('other.jsonl', "{}\n");

        $this->assertFailure(
            fn () => (new CompressionArchiveParser())->parse($archive),
            CompressionSyncFailureCode::ARCHIVE_FAILURE,
            'does not contain',
        );
    }

    public function test_rejects_malformed_jsonl(): void
    {
        $archive = $this->archiveWith('compressibleTypes.jsonl', "{bad json}\n");

        $this->assertFailure(
            fn () => (new CompressionArchiveParser())->parse($archive),
            CompressionSyncFailureCode::PARSE_FAILURE,
            'invalid JSON on line 1',
        );
    }

    public function test_rejects_jsonl_with_missing_stable_fields(): void
    {
        $archive = $this->archiveWith('compressibleTypes.jsonl', "{\"_key\":18}\n");

        $this->assertFailure(
            fn () => (new CompressionArchiveParser())->parse($archive),
            CompressionSyncFailureCode::PARSE_FAILURE,
            'invalid record shape',
        );
    }

    private function assertFailure(callable $operation, CompressionSyncFailureCode $code, string $message): void
    {
        try {
            $operation();
            self::fail('Expected parsing to fail.');
        } catch (CompressionSyncException $exception) {
            self::assertSame($code, $exception->failureCode);
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function archiveWith(string $member, string $contents): string
    {
        $path = $this->temporaryPath();
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($archive->addFromString($member, $contents));
        self::assertTrue($archive->close());

        return $path;
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'compression-parser-test-');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
