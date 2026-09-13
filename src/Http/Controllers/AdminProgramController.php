<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RecursiveTree\Seat\PricesCore\Models\PriceProviderInstance;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\ProgramHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\SaveProgramConfiguration;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables\AdminProgramsDataTable;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\StoreProgramRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\UpdateProgramRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class AdminProgramController
{
    public function index(AdminProgramsDataTable $dataTable): mixed
    {
        Gate::authorize('viewAny', BuybackProgram::class);

        return $dataTable->render('seat-buyback-programs::admin.programs.index');
    }

    public function create(): View
    {
        Gate::authorize('create', BuybackProgram::class);

        return $this->form(new BuybackProgram(), 'create');
    }

    public function store(StoreProgramRequest $request, SaveProgramConfiguration $save): RedirectResponse
    {
        [$program, $health] = $save->save(
            null,
            $request->programAttributes(ProgramStatus::DISABLED),
            $request->referenceAttributes(),
            (int) $request->user()->getAuthIdentifier(),
        );

        return redirect()->route('buyback.admin.programs.edit', $program)
            ->with('success', 'Buyback Program created.')
            ->with('warnings', $health->warnings);
    }

    public function edit(BuybackProgram $program, ProgramHealthService $health): View
    {
        Gate::authorize('view', $program);

        return $this->form($program, 'edit', $health->forProgram($program));
    }

    public function update(
        UpdateProgramRequest $request,
        BuybackProgram $program,
        SaveProgramConfiguration $save,
    ): RedirectResponse {
        [, $health] = $save->save(
            $program,
            $request->programAttributes($program->status),
            $request->referenceAttributes(),
            (int) $request->user()->getAuthIdentifier(),
        );

        return redirect()->route('buyback.admin.programs.edit', $program)
            ->with('success', 'Buyback Program updated.')
            ->with('warnings', $health->warnings);
    }

    public function archive(BuybackProgram $program): RedirectResponse
    {
        Gate::authorize('archive', $program);

        $program->status = ProgramStatus::ARCHIVED;
        $program->updated_by_user_id = (int) request()->user()->getAuthIdentifier();
        $program->save();

        return redirect()->route('buyback.admin.programs.index')
            ->with('success', 'Program archived. Existing Quotes and Buyback Requests are unaffected.');
    }

    private function form(BuybackProgram $program, string $mode, mixed $health = null): View
    {
        $program->loadMissing('priceReferences');

        return view('seat-buyback-programs::admin.programs.form', [
            'program' => $program,
            'mode' => $mode,
            'health' => $health,
            'providers' => PriceProviderInstance::query()->orderBy('name')->get(['id', 'name']),
            'references' => $program->priceReferences->keyBy(static fn ($reference): string => $reference->reference_mode->value),
        ]);
    }
}
