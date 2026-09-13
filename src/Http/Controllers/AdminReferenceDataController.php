<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\DispatchCompressionSync;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Compression\CompressionHealthService;
use RandulfTheGrey\Seat\BuybackPrograms\Models\CompressionMapping;
use Throwable;

final class AdminReferenceDataController
{
    public function index(CompressionHealthService $health): View
    {
        return view('seat-buyback-programs::admin.reference-data.index', [
            'health' => $health->current(),
            'mappingCount' => CompressionMapping::query()->count(),
        ]);
    }

    public function sync(DispatchCompressionSync $dispatcher): RedirectResponse
    {
        try {
            $result = $dispatcher->dispatch();
        } catch (Throwable $exception) {
            Log::error('Unable to queue compression data synchronization.', ['exception' => $exception]);

            return back()->with('error', 'Compression synchronization could not be queued. Try again or ask an operator to run the shared sync command.');
        }

        return back()->with($result->dispatched ? 'success' : 'status', $result->message);
    }
}
