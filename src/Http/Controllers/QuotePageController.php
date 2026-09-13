<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;

final class QuotePageController
{
    public function __invoke(Request $request, BuybackQuote $quote): View
    {
        abort_unless($request->user()->can('view', $quote), 404);
        $quote->load(['items', 'buybackRequest', 'program']);

        return view('seat-buyback-programs::quotes.show', [
            'quote' => $quote,
            'state' => $quote->stateAt(),
        ]);
    }
}
