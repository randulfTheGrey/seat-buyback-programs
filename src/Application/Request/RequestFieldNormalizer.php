<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidRequestFieldException;

final class RequestFieldNormalizer
{
    public static function contractId(?int $contractId): ?int
    {
        if ($contractId !== null && $contractId <= 0) {
            throw new InvalidRequestFieldException('The EVE contract ID must be a positive integer.');
        }

        return $contractId;
    }

    public static function note(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $note = trim($note);

        return $note === '' ? null : $note;
    }
}
