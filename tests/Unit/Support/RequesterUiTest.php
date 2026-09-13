<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi;

final class RequesterUiTest extends TestCase
{
    public function test_historical_type_compression_snapshot_source_remains_readable(): void
    {
        $rows = RequesterUi::policyExplanation([
            'baseline' => [
                'acceptance' => ['value' => 'ACCEPT'],
                'reference_mode' => ['value' => 'BUY'],
                'modifier' => ['value_bps' => 0],
            ],
            'acceptance' => 'ACCEPT',
            'reference_mode' => 'BUY',
            'effective_modifier_bps' => 0,
            'layers' => [[
                'source' => 'TYPE_COMPRESSION',
                'acceptance' => ['applied' => true, 'after' => 'ACCEPT'],
                'reference_mode' => ['applied' => false],
                'modifier' => ['applied' => false],
            ]],
        ]);

        self::assertSame('Compression-specific item rule', $rows[1]['label']);
        self::assertSame('Effective', $rows[2]['label']);
    }
}
