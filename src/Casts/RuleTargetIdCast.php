<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<int|null, int|string>
 */
final class RuleTargetIdCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : $this->validate($key, $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return $this->validate($key, $value);
    }

    private function validate(string $key, mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/D', $value) === 1)) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $key));
        }

        $targetId = (int) $value;

        if ($targetId <= 0) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $key));
        }

        return $targetId;
    }
}
