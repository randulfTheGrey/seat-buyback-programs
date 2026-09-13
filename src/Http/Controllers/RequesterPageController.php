<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use RandulfTheGrey\Seat\BuybackPrograms\Http\DataTables\RequesterBuybacksDataTable;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class RequesterPageController
{
    public function index(Request $request, RequesterBuybacksDataTable $dataTable): mixed
    {
        $viewData = [];

        if (! $request->ajax()) {
            $viewData['availableQuotes'] = BuybackQuote::query()
                ->availableForRequester((int) $request->user()->getAuthIdentifier())
                ->orderBy('expires_at')
                ->get([
                    'id',
                    'public_id',
                    'requester_user_id',
                    'program_name_snapshot',
                    'quoted_at',
                    'expires_at',
                    'payable_total',
                ]);
        }

        return $dataTable->render('seat-buyback-programs::requests.index', $viewData);
    }

    public function show(Request $request, BuybackRequest $buybackRequest): View
    {
        abort_unless($request->user()->can('viewAsRequester', $buybackRequest), 404);
        $buybackRequest->load(['quote.items', 'quote.program']);

        return view('seat-buyback-programs::requests.show', [
            'buybackRequest' => $buybackRequest,
        ]);
    }
}
