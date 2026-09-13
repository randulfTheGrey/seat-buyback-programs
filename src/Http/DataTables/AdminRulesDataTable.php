<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\RuleTargetNameResolver;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRule;
use RandulfTheGrey\Seat\BuybackPrograms\Support\AdminUi;
use Yajra\DataTables\Services\DataTable;

final class AdminRulesDataTable extends DataTable
{
    private BuybackProgram $program;

    public function __construct(private readonly RuleTargetNameResolver $targets)
    {
        parent::__construct();
    }

    public function forProgram(BuybackProgram $program): self
    {
        $this->program = $program;

        return $this;
    }

    public function ajax(): JsonResponse
    {
        abort_unless(request()->user()?->can('buyback.admin') === true, 403);

        return datatables()->eloquent($this->query())
            ->addColumn('target', fn (BuybackRule $row): string => e($this->targets->for(
                $this->program,
                $row->target_type,
                (int) $row->target_id,
            )))
            ->addColumn('scope', static fn (BuybackRule $row): string => ucfirst(strtolower($row->target_type->value)))
            ->addColumn('qualifier', static fn (BuybackRule $row): string => $row->target_type->value === 'TYPE'
                ? 'Exact item type'
                : match ($row->compression_qualifier->value) {
                    'ANY' => 'All matching forms',
                    'COMPRESSED' => 'Compressed only',
                    'UNCOMPRESSED' => 'Uncompressed only',
                })
            ->addColumn('acceptance_label', static fn (BuybackRule $row): string => match ($row->acceptance->value) {
                'INHERIT' => 'Inherit',
                'ACCEPT' => 'Explicitly accept',
                'REJECT' => 'Explicitly reject',
            })
            ->addColumn('reference_label', static fn (BuybackRule $row): string => $row->reference_mode_override?->value ?? 'Inherit')
            ->addColumn('modifier_label', static fn (BuybackRule $row): string => match ($row->modifier_operation->value) {
                'INHERIT' => 'Inherit',
                'REPLACE' => 'Replace: ' . AdminUi::modifierLabel($row->modifier_bps->value),
                'ADJUST' => 'Adjust: ' . AdminUi::modifierLabel($row->modifier_bps->value),
            })
            ->addColumn('lifecycle', static fn (BuybackRule $row): string => match (true) {
                $row->archived_at !== null => '<span class="badge badge-dark">Archived</span>',
                $row->enabled => '<span class="badge badge-success">Enabled</span>',
                default => '<span class="badge badge-secondary">Disabled</span>',
            })
            ->addColumn('action', fn (BuybackRule $row): string => sprintf(
                '<a class="btn btn-sm btn-primary" href="%s"><i class="fas fa-edit" aria-hidden="true"></i> Edit</a>',
                e(route('buyback.admin.programs.rules.edit', [$this->program, $row])),
            ))
            ->rawColumns(['lifecycle', 'action'])
            ->toJson();
    }

    public function html(): mixed
    {
        return $this->builder()
            ->minifiedAjax()
            ->columns($this->getColumns())
            ->orderBy(0)
            ->addAction(['title' => 'Action', 'orderable' => false, 'searchable' => false]);
    }

    public function query(): Builder
    {
        abort_unless(request()->user()?->can('buyback.admin') === true, 403);

        $query = $this->program->rules()->getQuery();

        if (! request()->boolean('show_archived')) {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function getColumns(): array
    {
        return [
            ['data' => 'target', 'name' => 'target_id', 'title' => 'Target', 'orderable' => false],
            ['data' => 'scope', 'name' => 'target_type', 'title' => 'Scope'],
            ['data' => 'qualifier', 'name' => 'compression_qualifier', 'title' => 'Compression'],
            ['data' => 'acceptance_label', 'name' => 'acceptance', 'title' => 'Acceptance'],
            ['data' => 'reference_label', 'name' => 'reference_mode_override', 'title' => 'Reference'],
            ['data' => 'modifier_label', 'name' => 'modifier_operation', 'title' => 'Modifier'],
            ['data' => 'lifecycle', 'name' => 'enabled', 'title' => 'State'],
        ];
    }
}
