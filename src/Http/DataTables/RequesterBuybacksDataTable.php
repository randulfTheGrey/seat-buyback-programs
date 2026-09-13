<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Support\RequesterUi;
use Yajra\DataTables\Services\DataTable;

final class RequesterBuybacksDataTable extends DataTable
{
    public function ajax(): JsonResponse
    {
        abort_unless(request()->user()?->can('buyback.request') === true, 403);

        return datatables()->eloquent($this->query())
            ->editColumn('submitted_at', static fn (BuybackRequest $row): string => sprintf(
                '<time datetime="%s">%s</time>',
                e($row->submitted_at->toIso8601String()),
                e($row->submitted_at->format('Y-m-d H:i:s T')),
            ))
            ->editColumn('payable_total', static fn (BuybackRequest $row): string =>
                e(RequesterUi::decimal((string) $row->getAttribute('payable_total'))) . ' ISK')
            ->editColumn('status', static fn (BuybackRequest $row): string => sprintf(
                '<span class="badge badge-%s">%s</span>',
                match ($row->status->value) {
                    'PENDING' => 'warning',
                    'COMPLETED' => 'success',
                    'REJECTED' => 'danger',
                    'CANCELED' => 'secondary',
                },
                e(ucfirst(strtolower($row->status->value))),
            ))
            ->editColumn('eve_contract_id', static fn (BuybackRequest $row): string =>
                $row->eve_contract_id === null ? '—' : e((string) $row->eve_contract_id))
            ->addColumn('action', static fn (BuybackRequest $row): string => sprintf(
                '<a class="btn btn-sm btn-primary" href="%s"><i class="fas fa-eye" aria-hidden="true"></i> View</a>',
                e(route('buyback.requests.show', $row)),
            ))
            ->rawColumns(['submitted_at', 'payable_total', 'status', 'action'])
            ->toJson();
    }

    public function html(): mixed
    {
        return $this->builder()
            ->minifiedAjax()
            ->columns($this->getColumns())
            ->orderBy(2, 'desc')
            ->addAction(['title' => 'Action', 'orderable' => false, 'searchable' => false]);
    }

    public function query(): Builder
    {
        abort_unless(request()->user()?->can('buyback.request') === true, 403);

        $requesterUserId = (int) request()->user()->getAuthIdentifier();
        abort_unless($requesterUserId > 0, 403);

        return BuybackRequest::query()
            ->select([
                'buyback_requests.id',
                'buyback_requests.public_id',
                'buyback_requests.quote_id',
                'buyback_requests.status',
                'buyback_requests.submitted_at',
                'buyback_requests.eve_contract_id',
                'buyback_quotes.public_id as quote_public_id',
                'buyback_quotes.program_name_snapshot',
                'buyback_quotes.payable_total',
            ])
            ->join('buyback_quotes', 'buyback_quotes.id', '=', 'buyback_requests.quote_id')
            ->where('buyback_quotes.requester_user_id', $requesterUserId);
    }

    /** @return list<array<string, mixed>> */
    public function getColumns(): array
    {
        return [
            ['data' => 'public_id', 'name' => 'buyback_requests.public_id', 'title' => 'Request reference'],
            ['data' => 'program_name_snapshot', 'name' => 'buyback_quotes.program_name_snapshot', 'title' => 'Program'],
            ['data' => 'submitted_at', 'name' => 'buyback_requests.submitted_at', 'title' => 'Submitted'],
            ['data' => 'payable_total', 'name' => 'buyback_quotes.payable_total', 'title' => 'Payable amount'],
            ['data' => 'status', 'name' => 'buyback_requests.status', 'title' => 'Status'],
            ['data' => 'eve_contract_id', 'name' => 'buyback_requests.eve_contract_id', 'title' => 'EVE contract ID'],
        ];
    }
}
