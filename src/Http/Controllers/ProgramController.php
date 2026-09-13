<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class ProgramController
{
    public function index(): View
    {
        $programs = BuybackProgram::query()
            ->where('status', ProgramStatus::ENABLED->value)
            ->orderBy('name')
            ->get();

        return view('seat-buyback-programs::programs.index', compact('programs'));
    }
}
