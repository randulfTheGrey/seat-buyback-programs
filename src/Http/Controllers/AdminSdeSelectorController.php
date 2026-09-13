<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\CompressionTargetApplicability;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionClassifier;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\CompressionQualifier;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;

final class AdminSdeSelectorController
{
    public function types(Request $request, CompressionTargetApplicability $compression): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = max(1, $request->integer('page', 1));
        $limit = 20;
        $query = InvType::query()->with('group')->where('published', true)->orderBy('typeName');

        if ($term = trim((string) $request->input('q'))) {
            $query->where('typeName', 'like', '%' . addcslashes($term, '%_\\') . '%');
        }

        $rows = $query->skip(($page - 1) * $limit)->take($limit + 1)->get();
        $applicability = $compression->forTypes($rows->pluck('typeID')->map(static fn ($id): int => (int) $id));
        $states = $compression->statesForTypes($rows->pluck('typeID')->map(static fn ($id): int => (int) $id));

        return response()->json([
            'results' => $rows->take($limit)->map(static fn ($type): array => [
                'id' => (int) $type->typeID,
                'text' => sprintf('%s — %s (#%d)', $type->typeName, $type->group->groupName, $type->typeID),
                'compression_applicable' => $applicability[(int) $type->typeID],
                'compression_state' => $states[(int) $type->typeID]?->value,
            ])->values(),
            'pagination' => ['more' => $rows->count() > $limit],
        ]);
    }

    public function groups(Request $request, CompressionTargetApplicability $compression): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = max(1, $request->integer('page', 1));
        $limit = 20;
        $query = InvGroup::query()->orderBy('groupName');

        if ($term = trim((string) $request->input('q'))) {
            $query->where('groupName', 'like', '%' . addcslashes($term, '%_\\') . '%');
        }

        $rows = $query->skip(($page - 1) * $limit)->take($limit + 1)->get();
        $qualifiers = $compression->qualifiersForGroups($rows->pluck('groupID')->map(static fn ($id): int => (int) $id));

        return response()->json([
            'results' => $rows->take($limit)->map(static fn ($group): array => [
                'id' => (int) $group->groupID,
                'text' => sprintf('%s — category #%d (group #%d)', $group->groupName, $group->categoryID, $group->groupID),
                'compression_applicable' => $qualifiers[(int) $group->groupID] === null
                    ? null
                    : count($qualifiers[(int) $group->groupID]) > 1,
                'compression_qualifiers' => $qualifiers[(int) $group->groupID] === null
                    ? null
                    : array_map(
                        static fn (CompressionQualifier $qualifier): string => $qualifier->value,
                        $qualifiers[(int) $group->groupID],
                    ),
            ])->values(),
            'pagination' => ['more' => $rows->count() > $limit],
        ]);
    }

    public function affectedTypes(
        Request $request,
        CompressionTargetApplicability $applicability,
        CompressionClassifier $classifier,
        CompressionHealthService $health,
    ): JsonResponse {
        $validated = $request->validate([
            'target_type' => ['required', Rule::enum(RuleTargetType::class)],
            'target_id' => ['required', 'integer', 'min:1'],
            'compression_qualifier' => ['required', Rule::enum(CompressionQualifier::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $targetType = RuleTargetType::from($validated['target_type']);
        $targetId = (int) $validated['target_id'];
        $qualifier = CompressionQualifier::from($validated['compression_qualifier']);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $limit = 25;

        abort_unless(
            $targetType === RuleTargetType::TYPE
                ? InvType::query()->where('published', true)->whereKey($targetId)->exists()
                : InvGroup::query()->whereKey($targetId)->exists(),
            404,
        );

        $availableQualifiers = $targetType === RuleTargetType::GROUP
            ? $applicability->qualifiersForGroups([$targetId])[$targetId]
            : [CompressionQualifier::ANY];
        $compressionApplicable = $targetType === RuleTargetType::GROUP
            ? ($availableQualifiers === null ? null : count($availableQualifiers) > 1)
            : $applicability->forTarget($targetType, $targetId);
        $targetCompressionState = $targetType === RuleTargetType::TYPE
            ? $applicability->stateForType($targetId)
            : null;
        $effectiveQualifier = $availableQualifiers !== null && ! in_array($qualifier, $availableQualifiers, true)
            ? CompressionQualifier::ANY
            : $qualifier;
        $compressionHealth = $health->current();
        $query = InvType::query()
            ->with('group')
            ->where('published', true)
            ->when(
                $targetType === RuleTargetType::TYPE,
                static fn ($query) => $query->whereKey($targetId),
                static fn ($query) => $query->where('groupID', $targetId),
            );

        if ($compressionHealth->usable) {
            if ($effectiveQualifier === CompressionQualifier::COMPRESSED) {
                $query->whereIn('typeID', CompressionMapping::query()->select('compressed_type_id'));
            } elseif ($effectiveQualifier === CompressionQualifier::UNCOMPRESSED) {
                $query->whereIn('typeID', CompressionMapping::query()->select('uncompressed_type_id'));
            }
        } elseif ($effectiveQualifier !== CompressionQualifier::ANY) {
            $query->whereRaw('1 = 0');
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $limit));
        $page = min($page, $lastPage);
        $rows = $query
            ->orderBy('typeName')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();
        $classifications = $compressionHealth->usable
            ? $classifier->classifyBatch($rows->pluck('typeID')->map(static fn ($id): int => (int) $id))
            : [];

        return response()->json([
            'compression_applicable' => $compressionApplicable,
            'compression_qualifiers' => $availableQualifiers === null
                ? null
                : array_map(
                    static fn (CompressionQualifier $available): string => $available->value,
                    $availableQualifiers,
                ),
            'target_compression_state' => $targetCompressionState?->value,
            'effective_qualifier' => $effectiveQualifier->value,
            'classification_available' => $compressionHealth->usable,
            'message' => ! $compressionHealth->usable && $effectiveQualifier !== CompressionQualifier::ANY
                ? 'Compression reference data is unavailable, so affected items cannot be determined for this qualifier.'
                : null,
            'items' => $rows->map(static fn ($type): array => [
                'id' => (int) $type->typeID,
                'name' => (string) $type->typeName,
                'group' => (string) $type->group->groupName,
                'compression_state' => ($classifications[(int) $type->typeID] ?? null)?->value,
            ])->values(),
            'pagination' => [
                'page' => $page,
                'last_page' => $lastPage,
                'per_page' => $limit,
                'total' => $total,
            ],
        ]);
    }
}
