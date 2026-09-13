<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Admin;

use Seat\Eveapi\Models\Sde\InvGroup;
use Seat\Eveapi\Models\Sde\InvType;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class RuleTargetNameResolver
{
    /** @var array<string, string>|null */
    private ?array $names = null;

    public function for(BuybackProgram $program, RuleTargetType $type, int $id): string
    {
        $this->names ??= $this->load($program);

        return $this->names[$type->value . ':' . $id] ?? sprintf('%s #%d (missing from SDE)', ucfirst(strtolower($type->value)), $id);
    }

    /** @return array<string, string> */
    private function load(BuybackProgram $program): array
    {
        $identities = $program->rules()
            ->get(['target_type', 'target_id'])
            ->groupBy(static fn ($rule): string => $rule->target_type->value);
        $names = [];

        foreach (InvType::query()
            ->whereIn('typeID', $identities->get('TYPE', collect())->pluck('target_id')->all())
            ->get(['typeID', 'typeName']) as $type) {
            $names['TYPE:' . $type->typeID] = (string) $type->typeName;
        }

        foreach (InvGroup::query()
            ->whereIn('groupID', $identities->get('GROUP', collect())->pluck('target_id')->all())
            ->get(['groupID', 'groupName']) as $group) {
            $names['GROUP:' . $group->groupID] = (string) $group->groupName;
        }

        return $names;
    }
}
