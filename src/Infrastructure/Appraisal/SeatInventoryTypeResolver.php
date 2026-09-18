<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Appraisal;

use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\InventoryTypeResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\ResolvedInventoryType;

final class SeatInventoryTypeResolver implements InventoryTypeResolver
{
    private const QUERY_CHUNK_SIZE = 500;

    public function resolveExact(iterable $candidateNames): array
    {
        /** @var array<string, array<string, true>> $requestedByLookupName */
        $requestedByLookupName = [];

        foreach ($candidateNames as $name) {
            if (is_string($name) && $name !== '') {
                $requestedByLookupName[$this->lookupName($name)][$name] = true;
            }
        }

        if ($requestedByLookupName === []) {
            return [];
        }

        /** @var array<string, ResolvedInventoryType> $resolvedByLookupName */
        $resolvedByLookupName = [];
        $ambiguous = [];

        foreach (array_chunk(array_keys($requestedByLookupName), self::QUERY_CHUNK_SIZE) as $lookupNames) {
            $types = InvType::query()
                ->where('published', true)
                ->whereIn(DB::raw('LOWER(typeName)'), $lookupNames)
                ->get(['typeID', 'typeName', 'groupID']);

            foreach ($types as $type) {
                $canonicalName = (string) $type->typeName;
                $lookupName = $this->lookupName($canonicalName);

                // Keep matching exact apart from case even when the database uses a
                // broader collation. No partial or fuzzy candidate is accepted here.
                if (! isset($requestedByLookupName[$lookupName])) {
                    continue;
                }

                if (isset($resolvedByLookupName[$lookupName])) {
                    $ambiguous[$lookupName] = true;
                    unset($resolvedByLookupName[$lookupName]);
                    continue;
                }

                if (! isset($ambiguous[$lookupName])) {
                    $resolvedByLookupName[$lookupName] = new ResolvedInventoryType(
                        (int) $type->typeID,
                        $canonicalName,
                        (int) $type->groupID,
                    );
                }
            }
        }

        $resolved = [];

        foreach ($resolvedByLookupName as $lookupName => $type) {
            foreach (array_keys($requestedByLookupName[$lookupName]) as $candidateName) {
                $resolved[$candidateName] = $type;
            }
        }

        ksort($resolved, SORT_STRING);

        return $resolved;
    }

    private function lookupName(string $name): string
    {
        return mb_strtolower($name, 'UTF-8');
    }
}
