<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables\ManagerBuybackRequestsDataTable;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\ManagerBuybackQueueRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class ManagerPageController
{
    public function index(
        ManagerBuybackQueueRequest $request,
        ManagerBuybackRequestsDataTable $dataTable,
    ): mixed {
        return $dataTable->render('seat-buyback-programs::manage.requests.index', [
            'programs' => BuybackProgram::query()
                ->whereHas('quotes.buybackRequest')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function show(ManagerBuybackQueueRequest $request, BuybackRequest $buybackRequest): View
    {
        abort_unless($request->user()->can('viewAsManager', $buybackRequest), 403);
        $buybackRequest->load(['quote.items', 'quote.program']);

        return view('seat-buyback-programs::manage.requests.show', [
            'buybackRequest' => $buybackRequest,
        ]);
    }
}
