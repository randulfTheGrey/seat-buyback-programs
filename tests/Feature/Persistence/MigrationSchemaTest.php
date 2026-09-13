<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Persistence;

use Illuminate\Support\Facades\Schema;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class MigrationSchemaTest extends TestCase
{
    public function test_plugin_migrations_create_and_reverse_all_owned_tables(): void
    {
        self::assertSame(0, $this->artisan('migrate')->run());

        foreach ($this->pluginTables() as $table) {
            self::assertTrue(Schema::hasTable($table), sprintf('%s was not created.', $table));
        }

        self::assertTrue(Schema::hasColumn('buyback_quotes', 'appraisal_token_hash'));

        self::assertSame(0, $this->artisan('migrate:rollback')->run());

        foreach ($this->pluginTables() as $table) {
            self::assertFalse(Schema::hasTable($table), sprintf('%s was not reversed.', $table));
        }
    }

    /**
     * @return list<string>
     */
    private function pluginTables(): array
    {
        return [
            'buyback_programs',
            'buyback_program_price_references',
            'buyback_rules',
            'buyback_compression_mappings',
            'buyback_compression_metadata',
            'buyback_quotes',
            'buyback_quote_items',
            'buyback_requests',
        ];
    }
}
