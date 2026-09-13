<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\ValueObjects\BasisPoints;

/**
 * @implements CastsAttributes<BasisPoints|null, BasisPoints|int|string|null>
 */
final class BasisPointsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BasisPoints
    {
        return $value === null ? null : new BasisPoints((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BasisPoints) {
            return $value->value;
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/^[+-]?\d+$/D', $value) === 1)) {
            throw new InvalidArgumentException(sprintf('%s must be an integer basis-point value.', $key));
        }

        return (new BasisPoints((int) $value))->value;
    }
}
