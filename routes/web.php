<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\AdminProgramController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\AdminReferenceDataController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\AdminRuleController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\AdminRulePreviewController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\AdminSdeSelectorController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\AppraisalController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\AppraisalPageController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\ManageBuybackRequestController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\ManagerPageController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\ProgramController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\QuoteController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\QuotePageController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\RequesterBuybackRequestController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\RequesterPageController;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers\SubmitBuybackQuoteController;

Route::middleware(['web', 'auth'])
    ->prefix((string) config('seat-buyback-programs.route_prefix', 'buyback'))
    ->name('buyback.')
    ->group(static function (): void {
        Route::middleware('can:randulfthegrey-buyback.request')->group(static function (): void {
            Route::get('', static fn () => redirect()->route('buyback.programs.index'))
                ->name('index');
            Route::get('programs', [ProgramController::class, 'index'])
                ->name('programs.index');
            Route::get('programs/{program}/appraisal', AppraisalPageController::class)
                ->name('appraisals.create');
            Route::post('programs/{program}/appraisals', AppraisalController::class)
                ->name('appraisals.store');

            Route::post('quotes', QuoteController::class)
                ->name('quotes.store');
            Route::get('quotes/{quote}', QuotePageController::class)
                ->name('quotes.show');
            Route::post('quotes/{quote}/submit', SubmitBuybackQuoteController::class)
                ->name('quotes.submit');

            Route::get('requests', [RequesterPageController::class, 'index'])
                ->name('requests.index');
            Route::get('requests/{buybackRequest}', [RequesterPageController::class, 'show'])
                ->name('requests.show');
            Route::patch('requests/{buybackRequest}/contract', [RequesterBuybackRequestController::class, 'contract'])
                ->name('requests.contract.update');
            Route::patch('requests/{buybackRequest}/requester-note', [RequesterBuybackRequestController::class, 'requesterNote'])
                ->name('requests.requester-note.update');
            Route::post('requests/{buybackRequest}/cancel', [RequesterBuybackRequestController::class, 'cancel'])
                ->name('requests.cancel');
        });
    });

Route::middleware(['web', 'auth', 'can:randulfthegrey-buyback.manage'])
    ->prefix((string) config('seat-buyback-programs.manager_route_prefix', 'buyback-manage'))
    ->name('buyback.manage.')
    ->group(static function (): void {
        Route::get('requests', [ManagerPageController::class, 'index'])
            ->name('requests.index');
        Route::get('requests/{buybackRequest}', [ManagerPageController::class, 'show'])
            ->name('requests.show');
        Route::patch('requests/{buybackRequest}/contract', [ManageBuybackRequestController::class, 'contract'])
            ->name('requests.contract.update');
        Route::patch('requests/{buybackRequest}/manager-note', [ManageBuybackRequestController::class, 'managerNote'])
            ->name('requests.manager-note.update');
        Route::post('requests/{buybackRequest}/complete', [ManageBuybackRequestController::class, 'complete'])
            ->name('requests.complete');
        Route::post('requests/{buybackRequest}/reject', [ManageBuybackRequestController::class, 'reject'])
            ->name('requests.reject');
    });

Route::middleware(['web', 'auth', 'can:randulfthegrey-buyback.admin'])
    ->prefix((string) config('seat-buyback-programs.admin_route_prefix', 'buyback-admin'))
    ->name('buyback.admin.')
    ->group(static function (): void {
        Route::get('', static fn () => redirect()->route('buyback.admin.programs.index'))
            ->name('index');
        Route::get('programs', [AdminProgramController::class, 'index'])
            ->name('programs.index');
        Route::get('programs/create', [AdminProgramController::class, 'create'])
            ->name('programs.create');
        Route::post('programs', [AdminProgramController::class, 'store'])
            ->name('programs.store');
        Route::get('programs/{program}/edit', [AdminProgramController::class, 'edit'])
            ->name('programs.edit');
        Route::patch('programs/{program}', [AdminProgramController::class, 'update'])
            ->name('programs.update');
        Route::patch('programs/{program}/archive', [AdminProgramController::class, 'archive'])
            ->name('programs.archive');

        Route::get('programs/{program}/rules', [AdminRuleController::class, 'index'])
            ->name('programs.rules.index');
        Route::get('programs/{program}/rules/create', [AdminRuleController::class, 'create'])
            ->name('programs.rules.create');
        Route::post('programs/{program}/rules', [AdminRuleController::class, 'store'])
            ->name('programs.rules.store');
        Route::get('programs/{program}/rules/{rule}/edit', [AdminRuleController::class, 'edit'])
            ->name('programs.rules.edit');
        Route::patch('programs/{program}/rules/{rule}', [AdminRuleController::class, 'update'])
            ->name('programs.rules.update');
        Route::patch('programs/{program}/rules/{rule}/archive', [AdminRuleController::class, 'archive'])
            ->name('programs.rules.archive');

        Route::get('rule-preview', AdminRulePreviewController::class)
            ->name('preview.index');
        Route::get('selectors/types', [AdminSdeSelectorController::class, 'types'])
            ->name('selectors.types');
        Route::get('selectors/groups', [AdminSdeSelectorController::class, 'groups'])
            ->name('selectors.groups');
        Route::get('selectors/affected-types', [AdminSdeSelectorController::class, 'affectedTypes'])
            ->name('selectors.affected-types');
        Route::get('reference-data', [AdminReferenceDataController::class, 'index'])
            ->name('reference-data.index');
        Route::post('reference-data/sync', [AdminReferenceDataController::class, 'sync'])
            ->name('reference-data.sync');
    });
