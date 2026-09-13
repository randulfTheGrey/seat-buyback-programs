<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Admin\RulePreviewService;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use Throwable;

final class AdminRulePreviewController
{
    public function __invoke(Request $request, RulePreviewService $previewService): View
    {
        $preview = null;
        $previewError = null;
        $program = null;
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer', 'min:1'],
            'type_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($validated['program_id'])) {
            $program = BuybackProgram::query()->findOrFail((int) $validated['program_id']);
        }

        if ($program !== null && isset($validated['type_id'])) {
            try {
                $preview = $previewService->preview($program, (int) $validated['type_id']);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                Log::warning('Buyback policy preview failed.', [
                    'program_id' => $program->id,
                    'type_id' => (int) $validated['type_id'],
                    'exception' => $exception,
                ]);
                $previewError = 'Preview is unavailable because the current Program policy is invalid. Review Program health and active rules.';
            }
        }

        return view('seat-buyback-programs::admin.preview.index', [
            'programs' => BuybackProgram::query()->where('status', '!=', 'ARCHIVED')->orderBy('name')->get(),
            'program' => $program,
            'preview' => $preview,
            'previewError' => $previewError,
        ]);
    }
}
