<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Compression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionClassifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class CompressionClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_classifies_both_canonical_sides_and_unmapped_types_in_one_query(): void
    {
        CompressionMapping::query()->insert([
            ['uncompressed_type_id' => 99000001, 'compressed_type_id' => 99100001],
            ['uncompressed_type_id' => 99000002, 'compressed_type_id' => 99100002],
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->app->make(CompressionClassifier::class)
            ->classifyBatch([99000001, 99100001, 999999, 99000001]);

        self::assertSame([
            99000001 => CompressionState::UNCOMPRESSED,
            99100001 => CompressionState::COMPRESSED,
            999999 => CompressionState::NOT_APPLICABLE,
        ], $result);

        $mappingSelects = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'buyback_compression_mappings'),
        );
        self::assertCount(1, $mappingSelects);
    }

    public function test_single_item_convenience_method_uses_exact_mapping_only(): void
    {
        CompressionMapping::create([
            'uncompressed_type_id' => 99000001,
            'compressed_type_id' => 99100001,
        ]);

        $classifier = $this->app->make(CompressionClassifier::class);

        self::assertSame(CompressionState::UNCOMPRESSED, $classifier->classify(99000001));
        self::assertSame(CompressionState::COMPRESSED, $classifier->classify(99100001));
        self::assertSame(CompressionState::NOT_APPLICABLE, $classifier->classify(123456));
    }

    public function test_a_name_like_compressed_type_is_not_inferred_without_a_mapping(): void
    {
        $resolvedType = ['type_id' => 424242, 'name' => 'Compressed Test Ore'];

        self::assertSame(
            CompressionState::NOT_APPLICABLE,
            $this->app->make(CompressionClassifier::class)->classify($resolvedType['type_id']),
        );
    }

    public function test_mapping_does_not_depend_on_a_seat_inv_type_row(): void
    {
        self::assertFalse(DB::getSchemaBuilder()->hasTable('invTypes'));

        CompressionMapping::create([
            'uncompressed_type_id' => 90000001,
            'compressed_type_id' => 90000002,
        ]);

        self::assertSame(
            CompressionState::COMPRESSED,
            $this->app->make(CompressionClassifier::class)->classify(90000002),
        );
    }

    public function test_malformed_active_data_with_an_id_on_both_sides_fails_clearly(): void
    {
        CompressionMapping::query()->insert([
            ['uncompressed_type_id' => 18, 'compressed_type_id' => 100],
            ['uncompressed_type_id' => 100, 'compressed_type_id' => 200],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('type ID 100 appears on both mapping sides');

        $this->app->make(CompressionClassifier::class)->classify(100);
    }
}
