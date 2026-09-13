<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class AppraisalPageController
{
    public function __invoke(BuybackProgram $program): View
    {
        abort_unless($program->status === ProgramStatus::ENABLED, 404);

        return view('seat-buyback-programs::appraisals.create', compact('program'));
    }
}
