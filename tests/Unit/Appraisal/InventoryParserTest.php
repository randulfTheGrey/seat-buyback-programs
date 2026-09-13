<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Tests\Unit\Appraisal;

use PHPUnit\Framework\TestCase;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Appraisal\InventoryParser;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\InventoryInputError;

final class InventoryParserTest extends TestCase
{
    public function test_parses_current_tab_delimited_clipboard_and_manual_whitespace_formats(): void
    {
        $result = (new InventoryParser())->parse(implode("\n", [
            "Tritanium\t1,234",
            'Caldari Navy Antimatter Charge L 10000',
            '  Targeting Range Script     2  ',
            '',
            "\t",
            "Tritanium\t5",
        ]));

        self::assertSame(4, $result->nonBlankLineCount);
        self::assertSame([], $result->invalidLines);
        self::assertSame(
            ['Tritanium', 'Caldari Navy Antimatter Charge L', 'Targeting Range Script', 'Tritanium'],
            array_map(static fn ($line): string => $line->candidateName, $result->parsedLines),
        );
        self::assertSame(
            [1234, 10000, 2, 5],
            array_map(static fn ($line): int => $line->quantity, $result->parsedLines),
        );
    }

    public function test_reports_each_malformed_meaning_as_structured_invalid_input(): void
    {
        $result = (new InventoryParser())->parse(implode("\n", [
            'Tritanium',
            'Tritanium nope',
            'Tritanium 1,23',
            'Tritanium 0',
            'Tritanium -4',
            '25',
            "\t10",
            'Tritanium 9223372036854775808',
            "Tritanium\t1\textra",
        ]));

        self::assertSame([], $result->parsedLines);
        self::assertSame([
            InventoryInputError::MISSING_QUANTITY,
            InventoryInputError::MALFORMED_QUANTITY,
            InventoryInputError::MALFORMED_QUANTITY,
            InventoryInputError::NON_POSITIVE_QUANTITY,
            InventoryInputError::NON_POSITIVE_QUANTITY,
            InventoryInputError::MISSING_ITEM_NAME,
            InventoryInputError::MISSING_ITEM_NAME,
            InventoryInputError::QUANTITY_OVERFLOW,
            InventoryInputError::MALFORMED_QUANTITY,
        ], array_map(static fn ($line): InventoryInputError => $line->error, $result->invalidLines));
    }
}
