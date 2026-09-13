<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionClassifier;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionState;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;

final class CompressionTargetApplicability
{
    public function __construct(
        private readonly CompressionHealthService $health,
        private readonly CompressionClassifier $classifier,
    ) {
    }

    public function stateForType(int $id): ?CompressionState
    {
        return $this->statesForTypes([$id])[$id];
    }

    /**
     * @param iterable<int> $ids
     * @return array<int, CompressionState|null>
     */
    public function statesForTypes(iterable $ids): array
    {
        $states = $this->emptyResult($ids);

        if ($states === [] || ! $this->health->current()->usable) {
            return $states;
        }

        return $this->classifier->classifyBatch(array_keys($states));
    }

    public function forTarget(RuleTargetType $type, int $id): ?bool
    {
        return $type === RuleTargetType::TYPE
            ? $this->forTypes([$id])[$id]
            : $this->forGroups([$id])[$id];
    }

    /**
     * @param iterable<int> $ids
     * @return array<int, bool|null>
     */
    public function forTypes(iterable $ids): array
    {
        $applicability = $this->emptyResult($ids);

        if ($applicability === [] || ! $this->health->current()->usable) {
            return $applicability;
        }

        $mapped = [];
        $targetIds = array_keys($applicability);
        $mappings = CompressionMapping::query()
            ->whereIn('uncompressed_type_id', $targetIds)
            ->orWhereIn('compressed_type_id', $targetIds)
            ->get(['uncompressed_type_id', 'compressed_type_id']);

        foreach ($mappings as $mapping) {
            $mapped[(int) $mapping->uncompressed_type_id] = true;
            $mapped[(int) $mapping->compressed_type_id] = true;
        }

        foreach ($applicability as $id => $unused) {
            $applicability[$id] = isset($mapped[$id]);
        }

        return $applicability;
    }

    /**
     * @param iterable<int> $ids
     * @return array<int, bool|null>
     */
    public function forGroups(iterable $ids): array
    {
        $qualifiers = $this->qualifiersForGroups($ids);

        foreach ($qualifiers as $id => $available) {
            $qualifiers[$id] = $available === null ? null : count($available) > 1;
        }

        return $qualifiers;
    }

    /**
     * @param iterable<int> $ids
     * @return array<int, list<CompressionQualifier>|null>
     */
    public function qualifiersForGroups(iterable $ids): array
    {
        $qualifiers = $this->emptyResult($ids);

        if ($qualifiers === [] || ! $this->health->current()->usable) {
            return $qualifiers;
        }

        $groupIds = array_keys($qualifiers);
        $uncompressedGroups = InvType::query()
            ->whereIn('groupID', $groupIds)
            ->whereIn('typeID', CompressionMapping::query()->select('uncompressed_type_id'))
            ->distinct()
            ->pluck('groupID')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $compressedGroups = InvType::query()
            ->whereIn('groupID', $groupIds)
            ->whereIn('typeID', CompressionMapping::query()->select('compressed_type_id'))
            ->distinct()
            ->pluck('groupID')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $uncompressed = array_fill_keys($uncompressedGroups, true);
        $compressed = array_fill_keys($compressedGroups, true);

        foreach ($qualifiers as $id => $unused) {
            $qualifiers[$id] = [CompressionQualifier::ANY];

            if (isset($compressed[$id])) {
                $qualifiers[$id][] = CompressionQualifier::COMPRESSED;
            }

            if (isset($uncompressed[$id])) {
                $qualifiers[$id][] = CompressionQualifier::UNCOMPRESSED;
            }
        }

        return $qualifiers;
    }

    /**
     * @param iterable<int> $ids
     * @return array<int, null>
     */
    private function emptyResult(iterable $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            if ($id > 0) {
                $result[$id] = null;
            }
        }

        return $result;
    }
}
