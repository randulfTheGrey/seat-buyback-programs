<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi;
use Yajra\DataTables\Services\DataTable;

final class ManagerBuybackRequestsDataTable extends DataTable
{
    public function ajax(): JsonResponse
    {
        abort_unless(request()->user()?->can('buyback.manage') === true, 403);

        return datatables()->eloquent($this->query())
            ->editColumn('public_id', static fn (BuybackRequest $row): string => sprintf(
                '<code>%s</code>',
                e($row->public_id),
            ))
            ->editColumn('submitted_at', static fn (BuybackRequest $row): string => sprintf(
                '<time datetime="%s">%s</time>',
                e($row->submitted_at->toIso8601String()),
                e($row->submitted_at->format('Y-m-d H:i:s T')),
            ))
            ->editColumn('payable_total', static fn (BuybackRequest $row): string =>
                e(RequesterUi::decimal((string) $row->getAttribute('payable_total'))) . ' ISK')
            ->editColumn('status', static fn (BuybackRequest $row): string => sprintf(
                '<span class="badge badge-%s">%s</span>',
                match ($row->status) {
                    BuybackRequestStatus::PENDING => 'warning',
                    BuybackRequestStatus::COMPLETED => 'success',
                    BuybackRequestStatus::REJECTED => 'danger',
                    BuybackRequestStatus::CANCELED => 'secondary',
                },
                e($row->status->value),
            ))
            ->editColumn('eve_contract_id', static fn (BuybackRequest $row): string =>
                $row->eve_contract_id === null ? '—' : e((string) $row->eve_contract_id))
            ->addColumn('action', static fn (BuybackRequest $row): string => sprintf(
                '<a class="btn btn-sm btn-primary" href="%s"><i class="fas fa-eye" aria-hidden="true"></i> View</a>',
                e(route('buyback.manage.requests.show', $row)),
            ))
            ->rawColumns(['public_id', 'submitted_at', 'payable_total', 'status', 'action'])
            ->toJson();
    }

    public function html(): mixed
    {
        return $this->builder()
            ->minifiedAjax()
            ->columns($this->getColumns())
            ->orderBy(7)
            ->orderBy(3, 'desc')
            ->addAction(['title' => 'Action', 'orderable' => false, 'searchable' => false]);
    }

    public function query(): Builder
    {
        abort_unless(request()->user()?->can('buyback.manage') === true, 403);

        $query = BuybackRequest::query()
            ->select([
                'buyback_requests.id',
                'buyback_requests.public_id',
                'buyback_requests.quote_id',
                'buyback_requests.status',
                'buyback_requests.submitted_at',
                'buyback_requests.eve_contract_id',
                'buyback_quotes.public_id as quote_public_id',
                'buyback_quotes.program_id',
                'buyback_quotes.program_name_snapshot',
                'buyback_quotes.requester_user_id',
                'buyback_quotes.requester_name_snapshot',
                'buyback_quotes.payable_total',
            ])
            ->selectRaw("CASE WHEN buyback_requests.status = 'PENDING' THEN 0 ELSE 1 END AS status_priority")
            ->join('buyback_quotes', 'buyback_quotes.id', '=', 'buyback_requests.quote_id')
            ->orderByRaw("CASE WHEN buyback_requests.status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('buyback_requests.submitted_at');

        if ($status = BuybackRequestStatus::tryFrom((string) request()->query('status'))) {
            $query->where('buyback_requests.status', $status->value);
        }

        $programId = request()->integer('program_id');
        if ($programId > 0) {
            $query->where('buyback_quotes.program_id', $programId);
        }

        $requester = trim((string) request()->query('requester'));
        if ($requester !== '') {
            $query->where('buyback_quotes.requester_name_snapshot', 'like',
                '%' . addcslashes($requester, '%_\\') . '%');
        }

        if ($submittedFrom = request()->query('submitted_from')) {
            $query->whereDate('buyback_requests.submitted_at', '>=', (string) $submittedFrom);
        }

        if ($submittedTo = request()->query('submitted_to')) {
            $query->whereDate('buyback_requests.submitted_at', '<=', (string) $submittedTo);
        }

        return $query;
    }

    /** @return list<array<string, mixed>> */
    public function getColumns(): array
    {
        return [
            ['data' => 'public_id', 'name' => 'buyback_requests.public_id', 'title' => 'Request reference'],
            ['data' => 'requester_name_snapshot', 'name' => 'buyback_quotes.requester_name_snapshot', 'title' => 'Requester'],
            ['data' => 'program_name_snapshot', 'name' => 'buyback_quotes.program_name_snapshot', 'title' => 'Program'],
            ['data' => 'submitted_at', 'name' => 'buyback_requests.submitted_at', 'title' => 'Submitted'],
            ['data' => 'payable_total', 'name' => 'buyback_quotes.payable_total', 'title' => 'Payable amount'],
            ['data' => 'status', 'name' => 'buyback_requests.status', 'title' => 'Status'],
            ['data' => 'eve_contract_id', 'name' => 'buyback_requests.eve_contract_id', 'title' => 'EVE contract ID'],
            ['data' => 'status_priority', 'name' => 'status_priority', 'title' => 'Priority', 'visible' => false, 'searchable' => false],
        ];
    }
}
