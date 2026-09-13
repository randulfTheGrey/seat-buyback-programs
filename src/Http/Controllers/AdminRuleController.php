<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\CompressionTargetApplicability;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\ProgramHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\RuleTargetNameResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\SaveRule;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\RuleTargetType;
use RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables\AdminRulesDataTable;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\StoreRuleRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\UpdateRuleRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;

final class AdminRuleController
{
    public function index(
        BuybackProgram $program,
        AdminRulesDataTable $dataTable,
        ProgramHealthService $health,
    ): mixed {
        Gate::authorize('viewAny', BuybackRule::class);

        return $dataTable->forProgram($program)->render('seat-buyback-programs::admin.rules.index', [
            'program' => $program,
            'health' => $health->forProgram($program),
        ]);
    }

    public function create(BuybackProgram $program, ProgramHealthService $health): View
    {
        Gate::authorize('create', BuybackRule::class);

        return view('seat-buyback-programs::admin.rules.form', [
            'program' => $program,
            'rule' => new BuybackRule(),
            'mode' => 'create',
            'referenceHealth' => $health->forProgram($program)->references,
            'targetName' => null,
            'compressionApplicable' => null,
            'compressionQualifiers' => null,
            'targetCompressionState' => null,
        ]);
    }

    public function store(StoreRuleRequest $request, BuybackProgram $program, SaveRule $save): RedirectResponse
    {
        $save->save($program, null, $request->ruleAttributes(), (int) $request->user()->getAuthIdentifier());

        return redirect()->route('buyback.admin.programs.rules.index', $program)
            ->with('success', 'Rule created.');
    }

    public function edit(
        BuybackProgram $program,
        BuybackRule $rule,
        ProgramHealthService $health,
        RuleTargetNameResolver $targets,
        CompressionTargetApplicability $compression,
    ): View
    {
        abort_unless((int) $rule->program_id === (int) $program->id, 404);
        Gate::authorize('view', $rule);

        return view('seat-buyback-programs::admin.rules.form', compact('program', 'rule') + [
            'mode' => 'edit',
            'referenceHealth' => $health->forProgram($program)->references,
            'targetName' => $targets->for($program, $rule->target_type, (int) $rule->target_id),
            'compressionApplicable' => $compression->forTarget($rule->target_type, (int) $rule->target_id),
            'compressionQualifiers' => $rule->target_type === RuleTargetType::GROUP
                ? $compression->qualifiersForGroups([(int) $rule->target_id])[(int) $rule->target_id]
                : null,
            'targetCompressionState' => $rule->target_type === RuleTargetType::TYPE
                ? $compression->stateForType((int) $rule->target_id)?->value
                : null,
        ]);
    }

    public function update(
        UpdateRuleRequest $request,
        BuybackProgram $program,
        BuybackRule $rule,
        SaveRule $save,
    ): RedirectResponse {
        $save->save($program, $rule, $request->ruleAttributes(), (int) $request->user()->getAuthIdentifier());

        return redirect()->route('buyback.admin.programs.rules.index', $program)
            ->with('success', 'Rule updated.');
    }

    public function archive(BuybackProgram $program, BuybackRule $rule): RedirectResponse
    {
        abort_unless((int) $rule->program_id === (int) $program->id, 404);
        Gate::authorize('archive', $rule);
        $rule->forceFill([
            'enabled' => false,
            'archived_at' => now(),
            'updated_by_user_id' => (int) request()->user()->getAuthIdentifier(),
        ])->save();

        return redirect()->route('buyback.admin.programs.rules.index', $program)
            ->with('success', 'Rule archived.');
    }
}
