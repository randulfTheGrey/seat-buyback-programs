<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionClassifier;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Admin\RulePreview;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\ItemPolicyContext;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Rules\PolicyEvaluator;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Persistence\PolicyInputMapper;

final class RulePreviewService
{
    public function __construct(
        private readonly CompressionClassifier $classifier,
        private readonly PolicyInputMapper $mapper,
        private readonly PolicyEvaluator $evaluator,
    ) {
    }

    public function preview(BuybackProgram $program, int $typeId): RulePreview
    {
        $type = InvType::query()->with('group')->where('published', true)->find($typeId);

        if ($type === null) {
            throw (new ModelNotFoundException())->setModel(InvType::class, [$typeId]);
        }

        $compression = $this->classifier->classify((int) $type->typeID);
        $rules = $program->rules()->where('enabled', true)->whereNull('archived_at')->get();
        $policy = $this->evaluator->evaluate(
            $this->mapper->defaults($program),
            new ItemPolicyContext((int) $type->typeID, (int) $type->groupID, $compression),
            $this->mapper->activeRules($rules),
        );

        return new RulePreview(
            (int) $type->typeID,
            (string) $type->typeName,
            (int) $type->groupID,
            (string) $type->group->groupName,
            $compression,
            $policy,
        );
    }
}
