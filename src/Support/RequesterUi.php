<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Support;

use RandulfTheGrey\Seat\BuybackPrograms\Enums\InventoryInputError;

final class RequesterUi
{
    public static function decimal(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, null);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        return ($negative ? '-' : '') . $whole . ($fraction === null ? '' : '.' . $fraction);
    }

    public static function modifier(?int $basisPoints): string
    {
        if ($basisPoints === null || $basisPoints === 0) {
            return 'No adjustment';
        }

        $percentage = self::basisPointsPercentage(abs($basisPoints));

        return $basisPoints < 0
            ? $percentage . '% discount'
            : $percentage . '% premium';
    }

    public static function inputError(?InventoryInputError $error): string
    {
        return match ($error) {
            InventoryInputError::MISSING_QUANTITY => 'Missing quantity',
            InventoryInputError::MALFORMED_QUANTITY => 'Invalid quantity',
            InventoryInputError::NON_POSITIVE_QUANTITY => 'Quantity must be greater than zero',
            InventoryInputError::MISSING_ITEM_NAME => 'Missing item name',
            InventoryInputError::QUANTITY_OVERFLOW => 'Quantity is too large',
            null => 'Invalid input',
        };
    }

    /**
     * @param array<string, mixed>|null $policy
     * @return list<array{label: string, acceptance?: string, reference?: string, modifier?: string, changes?: list<string>}>
     */
    public static function policyExplanation(?array $policy): array
    {
        if ($policy === null) {
            return [];
        }

        $rows = [];
        $baseline = (array) ($policy['baseline'] ?? []);

        if ($baseline !== []) {
            $rows[] = [
                'label' => 'Program defaults',
                'acceptance' => self::acceptance((string) data_get($baseline, 'acceptance.value', '')),
                'reference' => (string) data_get($baseline, 'reference_mode.value', ''),
                'modifier' => self::modifier((int) data_get($baseline, 'modifier.value_bps', 0)),
            ];
        }

        foreach ((array) ($policy['layers'] ?? []) as $layer) {
            $layer = (array) $layer;
            $changes = [];

            if ((bool) data_get($layer, 'acceptance.applied', false)) {
                $changes[] = 'Acceptance changed to '
                    . self::acceptance((string) data_get($layer, 'acceptance.after', '')) . '.';
            }

            if ((bool) data_get($layer, 'reference_mode.applied', false)) {
                $changes[] = 'Reference changed to '
                    . (string) data_get($layer, 'reference_mode.after', '') . '.';
            }

            if ((bool) data_get($layer, 'modifier.applied', false)) {
                $changes[] = 'Adjustment changed to '
                    . self::modifier((int) data_get($layer, 'modifier.after_bps', 0)) . '.';
            }

            if ($changes !== []) {
                $rows[] = [
                    'label' => self::policyLayerLabel((string) ($layer['source'] ?? '')),
                    'changes' => $changes,
                ];
            }
        }

        $rows[] = [
            'label' => 'Effective',
            'acceptance' => self::acceptance((string) ($policy['acceptance'] ?? '')),
            'reference' => (string) ($policy['reference_mode'] ?? ''),
            'modifier' => self::modifier((int) ($policy['effective_modifier_bps'] ?? 0)),
        ];

        return $rows;
    }

    private static function basisPointsPercentage(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        return $fraction === 0
            ? (string) $whole
            : $whole . '.' . rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }

    private static function acceptance(string $acceptance): string
    {
        return $acceptance === 'ACCEPT' ? 'Accepted' : 'Excluded';
    }

    private static function policyLayerLabel(string $source): string
    {
        return match ($source) {
            'GROUP' => 'Item group rule',
            'GROUP_COMPRESSION' => 'Compression-specific item group rule',
            'TYPE' => 'Item rule',
            'TYPE_COMPRESSION' => 'Compression-specific item rule',
            default => 'Policy rule',
        };
    }
}
