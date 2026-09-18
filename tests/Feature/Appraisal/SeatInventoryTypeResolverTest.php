<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Appraisal;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\InventoryTypeResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class SeatInventoryTypeResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invTypes', static function (Blueprint $table): void {
            $table->unsignedBigInteger('typeID')->primary();
            $table->unsignedBigInteger('groupID');
            $table->string('typeName');
            $table->boolean('published');
        });
    }

    public function test_bulk_resolves_published_exact_names_case_insensitively_without_guessing(): void
    {
        DB::table('invTypes')->insert([
            ['typeID' => 34, 'groupID' => 18, 'typeName' => 'Tritanium', 'published' => true],
            ['typeID' => 35, 'groupID' => 18, 'typeName' => 'Pyerite', 'published' => true],
            ['typeID' => 1230, 'groupID' => 465, 'typeName' => 'Compressed Veldspar', 'published' => true],
            ['typeID' => 999, 'groupID' => 18, 'typeName' => 'Internal Mineral', 'published' => false],
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolved = $this->app->make(InventoryTypeResolver::class)->resolveExact([
            'tritanium',
            'Tritaniu',
            'Internal Mineral',
            'Pyerite',
            'Tritanium',
            'Compressed Veldspar',
            'compressed veldspar',
            'COMPRESSED VELDSPAR',
            'cOmPrEsSeD vElDsPaR',
            'Compressed Veldspa',
            'Veldspar',
        ]);

        self::assertCount(7, $resolved);
        self::assertSame(34, $resolved['Tritanium']->typeId);
        self::assertSame(18, $resolved['Tritanium']->groupId);
        self::assertSame($resolved['Tritanium'], $resolved['tritanium']);

        foreach ([
            'Compressed Veldspar',
            'compressed veldspar',
            'COMPRESSED VELDSPAR',
            'cOmPrEsSeD vElDsPaR',
        ] as $candidate) {
            self::assertSame(1230, $resolved[$candidate]->typeId);
            self::assertSame(465, $resolved[$candidate]->groupId);
            self::assertSame('Compressed Veldspar', $resolved[$candidate]->typeName);
            self::assertSame($resolved['Compressed Veldspar'], $resolved[$candidate]);
        }

        self::assertArrayNotHasKey('Tritaniu', $resolved);
        self::assertArrayNotHasKey('Compressed Veldspa', $resolved);
        self::assertArrayNotHasKey('Veldspar', $resolved);
        self::assertArrayNotHasKey('Internal Mineral', $resolved);

        $queries = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'invTypes'),
        );
        self::assertCount(1, $queries);
    }
}
