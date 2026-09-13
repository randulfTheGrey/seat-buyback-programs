<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Pricing\ProgramReferenceConfigurationLoader;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceMode;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ReferenceResolution;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Tests\TestCase;

final class ProgramReferenceConfigurationLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_reference_rows_are_mapped_without_provider_foreign_keys(): void
    {
        $program = BuybackProgram::create([
            'name' => 'Ore',
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 60,
        ]);
        $program->priceReferences()->createMany([
            [
                'reference_mode' => ReferenceMode::BUY,
                'resolution' => ReferenceResolution::PROVIDER,
                'provider_instance_id' => 101,
            ],
            [
                'reference_mode' => ReferenceMode::SPLIT,
                'resolution' => ReferenceResolution::DERIVED_MIDPOINT,
                'provider_instance_id' => null,
            ],
        ]);

        $configuration = (new ProgramReferenceConfigurationLoader())->forProgram($program);

        self::assertSame(101, $configuration->get(ReferenceMode::BUY)?->providerInstanceId);
        self::assertSame(
            ReferenceResolution::DERIVED_MIDPOINT,
            $configuration->get(ReferenceMode::SPLIT)?->resolution,
        );
        self::assertNull($configuration->get(ReferenceMode::SELL));
    }

    public function test_invalid_stored_enum_data_is_left_unresolved_for_runtime_misconfiguration(): void
    {
        $program = BuybackProgram::create([
            'name' => 'Ore',
            'default_reference_mode' => ReferenceMode::BUY,
            'default_modifier_bps' => 0,
            'quote_validity_minutes' => 60,
        ]);
        DB::table('buyback_program_price_references')->insert([
            'program_id' => $program->id,
            'reference_mode' => 'BUY',
            'resolution' => 'BROKEN',
            'provider_instance_id' => 101,
        ]);

        $configuration = (new ProgramReferenceConfigurationLoader())->forProgram($program);

        self::assertNull($configuration->get(ReferenceMode::BUY));
    }
}
