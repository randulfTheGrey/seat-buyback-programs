<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Appraisal;

use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\InventoryTypeResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\ResolvedInventoryType;

final class SeatInventoryTypeResolver implements InventoryTypeResolver
{
    private const QUERY_CHUNK_SIZE = 500;

    public function resolveExact(iterable $candidateNames): array
    {
        $requested = [];

        foreach ($candidateNames as $name) {
            if (is_string($name) && $name !== '') {
                $requested[$name] = true;
            }
        }

        if ($requested === []) {
            return [];
        }

        $resolved = [];
        $ambiguous = [];

        foreach (array_chunk(array_keys($requested), self::QUERY_CHUNK_SIZE) as $names) {
            $types = InvType::query()
                ->where('published', true)
                ->whereIn('typeName', $names)
                ->get(['typeID', 'typeName', 'groupID']);

            foreach ($types as $type) {
                $name = (string) $type->typeName;

                // Some database collations make whereIn case-insensitive. Financial
                // resolution remains exact by checking the returned SDE value here.
                if (! isset($requested[$name])) {
                    continue;
                }

                if (isset($resolved[$name])) {
                    $ambiguous[$name] = true;
                    unset($resolved[$name]);
                    continue;
                }

                if (! isset($ambiguous[$name])) {
                    $resolved[$name] = new ResolvedInventoryType(
                        (int) $type->typeID,
                        $name,
                        (int) $type->groupID,
                    );
                }
            }
        }

        ksort($resolved, SORT_STRING);

        return $resolved;
    }
}
