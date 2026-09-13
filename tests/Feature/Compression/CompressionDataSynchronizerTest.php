<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Compression;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionDataSynchronizer;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionProjectionStore;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\CompressionSdeSource;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionCandidate;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\CompressionPair;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Compression\SdeBuild;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionDataStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncFailureCode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionSyncOutcome;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionSyncException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMetadata;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;
use ZipArchive;

final class CompressionDataSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_valid_fixture_import_activates_every_mapping_and_metadata(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 12:00:00 UTC');
        $source = $this->sourceFor('3503375', $this->validFixtureArchive());

        $result = $this->synchronizer($source)->synchronize();

        self::assertSame(CompressionSyncOutcome::UPDATED, $result->outcome);
        self::assertSame(3, $result->mappingCount);
        self::assertSame([
            ['uncompressed_type_id' => 99000001, 'compressed_type_id' => 99100001],
            ['uncompressed_type_id' => 99000002, 'compressed_type_id' => 99100002],
            ['uncompressed_type_id' => 99000003, 'compressed_type_id' => 99100003],
        ], CompressionMapping::query()->orderBy('uncompressed_type_id')->get()->map->only([
            'uncompressed_type_id',
            'compressed_type_id',
        ])->all());

        $metadata = CompressionMetadata::query()->findOrFail(CompressionProjectionStore::METADATA_ID);
        self::assertSame('3503375', $metadata->active_sde_build);
        self::assertSame('CCP Static Data Export', $metadata->source);
        self::assertSame('3503375', $metadata->source_metadata['build_number']);
        self::assertSame('2026-09-11 12:00:00', $metadata->imported_at->format('Y-m-d H:i:s'));
        self::assertEquals($metadata->imported_at, $metadata->activated_at);
        self::assertNull($metadata->last_error_at);

        $health = $this->app->make(CompressionHealthService::class)->current();
        self::assertSame(CompressionDataStatus::AVAILABLE, $health->status);
        self::assertTrue($health->usable);
        self::assertFalse($health->lastRefreshFailed);
    }

    public function test_same_build_no_ops_without_downloading_or_changing_mappings(): void
    {
        $this->seedActiveDataset('100', [[18, 100]]);
        $source = $this->sourceFor('100', $this->validFixtureArchive());
        CarbonImmutable::setTestNow('2026-09-11 13:00:00 UTC');

        $result = $this->synchronizer($source)->synchronize();

        self::assertSame(CompressionSyncOutcome::CURRENT, $result->outcome);
        self::assertSame(0, $source->downloadCalls);
        self::assertSame([[18, 100]], $this->storedPairs());
        self::assertSame(
            '2026-09-11 13:00:00',
            CompressionMetadata::query()->findOrFail(1)->last_checked_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_new_build_atomically_replaces_and_removes_old_mappings(): void
    {
        $this->seedActiveDataset('100', [[18, 100], [999, 1999]]);

        $result = $this->synchronizer(
            $this->sourceFor('200', $this->validFixtureArchive()),
        )->synchronize();

        self::assertSame(CompressionSyncOutcome::UPDATED, $result->outcome);
        self::assertSame('200', CompressionMetadata::query()->findOrFail(1)->active_sde_build);
        self::assertSame([
            [99000001, 99100001],
            [99000002, 99100002],
            [99000003, 99100003],
        ], $this->storedPairs());
        self::assertFalse(CompressionMapping::query()->where('uncompressed_type_id', 999)->exists());
    }

    public function test_download_failure_preserves_last_known_good_dataset(): void
    {
        $this->seedActiveDataset('100', [[18, 100]]);
        $source = $this->sourceFor('200', $this->validFixtureArchive());
        $source->downloadFailure = new CompressionSyncException(
            CompressionSyncFailureCode::DOWNLOAD_FAILURE,
            'Unable to download CCP SDE build 200.',
        );

        $result = $this->synchronizer($source)->synchronize();

        $this->assertFailedRefreshPreservedDataset($result, CompressionSyncFailureCode::DOWNLOAD_FAILURE);
    }

    public function test_invalid_archive_preserves_last_known_good_dataset(): void
    {
        $this->seedActiveDataset('100', [[18, 100]]);
        $path = tempnam(sys_get_temp_dir(), 'compression-invalid-archive-');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, 'not a ZIP archive');

        $result = $this->synchronizer($this->sourceFor('200', $path))->synchronize();

        $this->assertFailedRefreshPreservedDataset($result, CompressionSyncFailureCode::ARCHIVE_FAILURE);
    }

    /**
     * @return iterable<string, array{string, CompressionSyncFailureCode}>
     */
    public static function invalidJsonlProvider(): iterable
    {
        yield 'malformed JSONL' => ["{bad json}\n", CompressionSyncFailureCode::PARSE_FAILURE];
        yield 'empty dataset' => ['', CompressionSyncFailureCode::VALIDATION_FAILURE];
        yield 'duplicate uncompressed ID' => [
            "{\"_key\":18,\"compressedTypeID\":100}\n{\"_key\":18,\"compressedTypeID\":101}\n",
            CompressionSyncFailureCode::VALIDATION_FAILURE,
        ];
        yield 'duplicate compressed ID' => [
            "{\"_key\":18,\"compressedTypeID\":100}\n{\"_key\":19,\"compressedTypeID\":100}\n",
            CompressionSyncFailureCode::VALIDATION_FAILURE,
        ];
        yield 'conflicting cross-side mapping' => [
            "{\"_key\":18,\"compressedTypeID\":100}\n{\"_key\":100,\"compressedTypeID\":200}\n",
            CompressionSyncFailureCode::VALIDATION_FAILURE,
        ];
    }

    #[DataProvider('invalidJsonlProvider')]
    public function test_invalid_candidate_preserves_last_known_good_dataset(
        string $jsonl,
        CompressionSyncFailureCode $failureCode,
    ): void {
        $this->seedActiveDataset('100', [[18, 100]]);
        $source = $this->sourceFor('200', $this->archiveWith($jsonl));

        $result = $this->synchronizer($source)->synchronize();

        $this->assertFailedRefreshPreservedDataset($result, $failureCode);
    }

    public function test_activation_exception_rolls_back_both_mapping_and_active_build(): void
    {
        $this->seedActiveDataset('100', [[18, 100]]);
        $failNextMappingInsert = true;
        DB::listen(static function ($query) use (&$failNextMappingInsert): void {
            if ($failNextMappingInsert
                && str_contains($query->sql, 'insert into "buyback_compression_mappings"')) {
                $failNextMappingInsert = false;
                throw new RuntimeException('simulated activation failure');
            }
        });

        $result = $this->synchronizer(
            $this->sourceFor('200', $this->validFixtureArchive()),
        )->synchronize();

        $this->assertFailedRefreshPreservedDataset($result, CompressionSyncFailureCode::ACTIVATION_FAILURE);
    }

    public function test_initial_failure_records_missing_unusable_health_without_partial_rows(): void
    {
        $source = $this->sourceFor('200', $this->archiveWith(''));

        $result = $this->synchronizer($source)->synchronize();
        $health = $this->app->make(CompressionHealthService::class)->current();

        self::assertSame(CompressionSyncOutcome::FAILED, $result->outcome);
        self::assertSame(CompressionDataStatus::MISSING, $health->status);
        self::assertFalse($health->usable);
        self::assertNull($health->activeBuild);
        self::assertTrue($health->lastRefreshFailed);
        self::assertSame(CompressionSyncFailureCode::VALIDATION_FAILURE, $health->lastFailureCode);
        self::assertSame(0, CompressionMapping::query()->count());
    }

    public function test_second_sync_cannot_overlap_and_lock_releases_after_success(): void
    {
        $source = $this->sourceFor('200', $this->validFixtureArchive());
        $synchronizer = $this->synchronizer($source);
        $nestedResult = null;
        $source->beforeLatest = function () use ($synchronizer, &$nestedResult, $source): void {
            $source->beforeLatest = null;
            $nestedResult = $synchronizer->synchronize();
        };

        $outerResult = $synchronizer->synchronize();
        $afterResult = $synchronizer->synchronize();

        self::assertSame(CompressionSyncOutcome::LOCKED, $nestedResult?->outcome);
        self::assertSame(CompressionSyncFailureCode::CONCURRENT_SYNC, $nestedResult?->failureCode);
        self::assertSame(CompressionSyncOutcome::UPDATED, $outerResult->outcome);
        self::assertSame(CompressionSyncOutcome::CURRENT, $afterResult->outcome);
    }

    public function test_lock_releases_after_failure(): void
    {
        $source = $this->sourceFor('200', $this->validFixtureArchive());
        $source->latestFailure = new CompressionSyncException(
            CompressionSyncFailureCode::BUILD_LOOKUP_FAILURE,
            'Unable to retrieve the current CCP SDE build.',
        );
        $synchronizer = $this->synchronizer($source);

        $failed = $synchronizer->synchronize();
        $source->latestFailure = null;
        $succeeded = $synchronizer->synchronize();

        self::assertSame(CompressionSyncOutcome::FAILED, $failed->outcome);
        self::assertSame(CompressionSyncOutcome::UPDATED, $succeeded->outcome);
        self::assertSame(
            CompressionDataStatus::AVAILABLE,
            $this->app->make(CompressionHealthService::class)->current()->status,
        );
    }

    private function assertFailedRefreshPreservedDataset(
        $result,
        CompressionSyncFailureCode $failureCode,
    ): void {
        self::assertSame(CompressionSyncOutcome::FAILED, $result->outcome);
        self::assertSame($failureCode, $result->failureCode);
        self::assertSame([[18, 100]], $this->storedPairs());
        self::assertSame('100', CompressionMetadata::query()->findOrFail(1)->active_sde_build);

        $health = $this->app->make(CompressionHealthService::class)->current();
        self::assertSame(CompressionDataStatus::STALE, $health->status);
        self::assertTrue($health->usable);
        self::assertTrue($health->lastRefreshFailed);
        self::assertSame($failureCode, $health->lastFailureCode);
        self::assertNotNull($health->lastFailureMessage);
        self::assertSame('200', $health->lastAttemptedBuild);
    }

    /** @param list<array{int, int}> $pairs */
    private function seedActiveDataset(string $build, array $pairs): void
    {
        $candidate = new CompressionCandidate(array_map(
            static fn (array $pair): CompressionPair => new CompressionPair($pair[0], $pair[1]),
            $pairs,
        ));
        $this->app->make(CompressionProjectionStore::class)->activate(
            $candidate,
            new SdeBuild($build, null, 'https://example.invalid/sde.zip'),
            CarbonImmutable::parse('2026-09-10 12:00:00 UTC'),
        );
    }

    /** @return list<array{int, int}> */
    private function storedPairs(): array
    {
        return CompressionMapping::query()
            ->orderBy('uncompressed_type_id')
            ->get()
            ->map(static fn (CompressionMapping $mapping): array => [
                $mapping->uncompressed_type_id,
                $mapping->compressed_type_id,
            ])->all();
    }

    private function synchronizer(FakeCompressionSdeSource $source): CompressionDataSynchronizer
    {
        $this->app->instance(CompressionSdeSource::class, $source);

        return $this->app->make(CompressionDataSynchronizer::class);
    }

    private function sourceFor(string $build, string $archive): FakeCompressionSdeSource
    {
        return new FakeCompressionSdeSource(
            new SdeBuild($build, null, sprintf('https://example.invalid/%s.zip', $build)),
            $archive,
        );
    }

    private function validFixtureArchive(): string
    {
        return $this->archiveWith((string) file_get_contents(
            dirname(__DIR__, 2) . '/Fixtures/compression/valid-compressibleTypes.jsonl',
        ));
    }

    private function archiveWith(string $jsonl): string
    {
        $path = tempnam(sys_get_temp_dir(), 'compression-sync-test-');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        $archive = new ZipArchive();
        self::assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($archive->addFromString('compressibleTypes.jsonl', $jsonl));
        self::assertTrue($archive->close());

        return $path;
    }
}

final class FakeCompressionSdeSource implements CompressionSdeSource
{
    public int $downloadCalls = 0;

    public ?CompressionSyncException $latestFailure = null;

    public ?CompressionSyncException $downloadFailure = null;

    public ?Closure $beforeLatest = null;

    public function __construct(
        public SdeBuild $build,
        public string $archivePath,
    ) {
    }

    public function latestBuild(): SdeBuild
    {
        ($this->beforeLatest) && ($this->beforeLatest)();

        if ($this->latestFailure !== null) {
            throw $this->latestFailure;
        }

        return $this->build;
    }

    public function download(SdeBuild $build, string $destination): void
    {
        $this->downloadCalls++;

        if ($this->downloadFailure !== null) {
            throw $this->downloadFailure;
        }

        if (! copy($this->archivePath, $destination)) {
            throw new RuntimeException('Unable to copy fake archive.');
        }
    }
}
