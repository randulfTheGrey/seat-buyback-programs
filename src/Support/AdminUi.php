<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Support;

use InvalidArgumentException;

final class AdminUi
{
    public static function modifierBps(string $direction, string $percentage): int
    {
        if (! in_array($direction, ['discount', 'premium', 'none'], true)) {
            throw new InvalidArgumentException('Select Discount, Premium, or None.');
        }

        if ($direction === 'none') {
            return 0;
        }

        $value = trim($percentage);

        if (! preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException('Percentage must use at most two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $bps = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        if ($bps > 10000) {
            throw new InvalidArgumentException('Percentage may not exceed 100.00%.');
        }

        return $direction === 'discount' ? -$bps : $bps;
    }

    /** @return array{direction: string, percentage: string} */
    public static function modifierInput(?int $bps): array
    {
        $bps ??= 0;
        $direction = $bps < 0 ? 'discount' : ($bps > 0 ? 'premium' : 'none');
        $absolute = abs($bps);

        return [
            'direction' => $direction,
            'percentage' => number_format($absolute / 100, 2, '.', ''),
        ];
    }

    public static function modifierLabel(int $bps): string
    {
        if ($bps === 0) {
            return 'None';
        }

        return sprintf(
            '%s%% %s',
            rtrim(rtrim(number_format(abs($bps) / 100, 2, '.', ''), '0'), '.'),
            $bps < 0 ? 'discount' : 'premium',
        );
    }
}
