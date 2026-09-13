<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Appraisal;

use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\InvalidInventoryLine;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\InventoryParseResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\ParsedInventoryLine;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\InventoryInputError;

final class InventoryParser
{
    public function parse(string $input): InventoryParseResult
    {
        $parsed = [];
        $invalid = [];
        $nonBlankLineCount = 0;
        $lines = preg_split('/\R/u', $input) ?: [];

        foreach ($lines as $offset => $rawLine) {
            $lineNumber = $offset + 1;
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            $nonBlankLineCount++;
            [$name, $quantityToken, $shapeError] = $this->splitLine($line);

            if ($shapeError !== null) {
                $invalid[] = new InvalidInventoryLine($lineNumber, $rawLine, $shapeError, $name);
                continue;
            }

            if ($name === '') {
                $invalid[] = new InvalidInventoryLine(
                    $lineNumber,
                    $rawLine,
                    InventoryInputError::MISSING_ITEM_NAME,
                );
                continue;
            }

            if (! $this->isValidQuantityToken($quantityToken)) {
                $invalid[] = new InvalidInventoryLine(
                    $lineNumber,
                    $rawLine,
                    InventoryInputError::MALFORMED_QUANTITY,
                    $name,
                );
                continue;
            }

            $digits = str_replace(',', '', $quantityToken);

            if ($digits[0] === '-') {
                $invalid[] = new InvalidInventoryLine(
                    $lineNumber,
                    $rawLine,
                    InventoryInputError::NON_POSITIVE_QUANTITY,
                    $name,
                );
                continue;
            }

            $digits = ltrim($digits, '+');

            if ($this->exceedsPhpInteger($digits)) {
                $invalid[] = new InvalidInventoryLine(
                    $lineNumber,
                    $rawLine,
                    InventoryInputError::QUANTITY_OVERFLOW,
                    $name,
                );
                continue;
            }

            $quantity = (int) $digits;

            if ($quantity <= 0) {
                $invalid[] = new InvalidInventoryLine(
                    $lineNumber,
                    $rawLine,
                    InventoryInputError::NON_POSITIVE_QUANTITY,
                    $name,
                );
                continue;
            }

            $parsed[] = new ParsedInventoryLine($lineNumber, $rawLine, $name, $quantity);
        }

        return new InventoryParseResult($parsed, $invalid, $nonBlankLineCount);
    }

    /** @return array{string, string, ?InventoryInputError} */
    private function splitLine(string $line): array
    {
        if (str_contains($line, "\t")) {
            $columns = preg_split('/\t+/u', $line) ?: [];

            if (count($columns) !== 2) {
                return [trim($columns[0] ?? ''), '', InventoryInputError::MALFORMED_QUANTITY];
            }

            return [trim($columns[0]), trim($columns[1]), null];
        }

        if (preg_match('/^(?<name>.*?)\s+(?<quantity>\S+)$/u', $line, $matches) === 1) {
            return [trim($matches['name']), trim($matches['quantity']), null];
        }

        if (preg_match('/^[+-]?[0-9][0-9,]*$/', $line) === 1) {
            return ['', $line, null];
        }

        return [trim($line), '', InventoryInputError::MISSING_QUANTITY];
    }

    private function isValidQuantityToken(string $token): bool
    {
        return preg_match('/^[+-]?(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)$/', $token) === 1;
    }

    private function exceedsPhpInteger(string $digits): bool
    {
        $normalized = ltrim($digits, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;

        return strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0);
    }
}
