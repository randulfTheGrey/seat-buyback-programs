<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\ProgramHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi;
use Yajra\DataTables\Services\DataTable;

final class AdminProgramsDataTable extends DataTable
{
    public function __construct(private readonly ProgramHealthService $health)
    {
        parent::__construct();
    }

    public function ajax(): JsonResponse
    {
        abort_unless(request()->user()?->can('buyback.admin') === true, 403);

        return datatables()->eloquent($this->query())
            ->editColumn('status', static fn (BuybackProgram $row): string => sprintf(
                '<span class="badge badge-%s">%s</span>',
                match ($row->status->value) {
                    'ENABLED' => 'success',
                    'DISABLED' => 'secondary',
                    'ARCHIVED' => 'dark',
                },
                e(ucfirst(strtolower($row->status->value))),
            ))
            ->addColumn('default_acceptance_label', static fn (BuybackProgram $row): string =>
                $row->default_acceptance->value === 'ACCEPT' ? 'Accept unless rejected' : 'Reject unless accepted')
            ->addColumn('default_reference', static fn (BuybackProgram $row): string => e($row->default_reference_mode->value))
            ->addColumn('default_modifier', static fn (BuybackProgram $row): string => e(AdminUi::modifierLabel($row->default_modifier_bps->value)))
            ->addColumn('health', function (BuybackProgram $row): string {
                $health = $this->health->forProgram($row);

                return sprintf(
                    '<span class="badge badge-%s" title="%s">%s</span>',
                    $health->operational() ? 'success' : 'warning',
                    e(implode(' ', array_merge($health->configuration->errors, $health->runtimeErrors))),
                    e($health->label()),
                );
            })
            ->addColumn('action', static fn (BuybackProgram $row): string => sprintf(
                '<a class="btn btn-sm btn-primary" href="%s"><i class="fas fa-edit" aria-hidden="true"></i> Edit</a> '
                . '<a class="btn btn-sm btn-outline-secondary" href="%s">Rules</a>',
                e(route('buyback.admin.programs.edit', $row)),
                e(route('buyback.admin.programs.rules.index', $row)),
            ))
            ->rawColumns(['status', 'health', 'action'])
            ->toJson();
    }

    public function html(): mixed
    {
        return $this->builder()
            ->minifiedAjax()
            ->columns($this->getColumns())
            ->orderBy(0)
            ->addAction(['title' => 'Actions', 'orderable' => false, 'searchable' => false]);
    }

    public function query(): Builder
    {
        abort_unless(request()->user()?->can('buyback.admin') === true, 403);

        return BuybackProgram::query()->with(['priceReferences', 'rules']);
    }

    /** @return list<array<string, mixed>> */
    public function getColumns(): array
    {
        return [
            ['data' => 'name', 'name' => 'buyback_programs.name', 'title' => 'Name'],
            ['data' => 'status', 'name' => 'buyback_programs.status', 'title' => 'Status'],
            ['data' => 'default_acceptance_label', 'name' => 'default_acceptance', 'title' => 'Default acceptance'],
            ['data' => 'default_reference', 'name' => 'default_reference_mode', 'title' => 'Default reference'],
            ['data' => 'default_modifier', 'name' => 'default_modifier_bps', 'title' => 'Default adjustment'],
            ['data' => 'quote_validity_minutes', 'name' => 'quote_validity_minutes', 'title' => 'Validity (minutes)'],
            ['data' => 'health', 'name' => 'id', 'title' => 'Health', 'orderable' => false, 'searchable' => false],
        ];
    }
}
