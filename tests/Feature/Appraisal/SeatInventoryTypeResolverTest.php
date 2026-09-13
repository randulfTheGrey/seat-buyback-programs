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

    public function test_bulk_resolves_only_published_exact_case_sensitive_names(): void
    {
        DB::table('invTypes')->insert([
            ['typeID' => 34, 'groupID' => 18, 'typeName' => 'Tritanium', 'published' => true],
            ['typeID' => 35, 'groupID' => 18, 'typeName' => 'Pyerite', 'published' => true],
            ['typeID' => 999, 'groupID' => 18, 'typeName' => 'Internal Mineral', 'published' => false],
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolved = $this->app->make(InventoryTypeResolver::class)->resolveExact([
            'Tritanium',
            'tritanium',
            'Tritaniu',
            'Internal Mineral',
            'Pyerite',
            'Tritanium',
        ]);

        self::assertSame(['Pyerite', 'Tritanium'], array_keys($resolved));
        self::assertSame(34, $resolved['Tritanium']->typeId);
        self::assertSame(18, $resolved['Tritanium']->groupId);

        $queries = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'], 'invTypes'),
        );
        self::assertCount(1, $queries);
    }
}
