<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Appraisal\InventoryAppraisalService;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalInfrastructureException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalInputException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalLimitExceededException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalPolicyException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalPricingConfigurationException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalStorageException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\CompressionReferenceDataUnavailableException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\ProgramUnavailableForAppraisalException;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\CreateAppraisalRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use Throwable;

final class AppraisalController
{
    public function __invoke(
        CreateAppraisalRequest $request,
        BuybackProgram $program,
        InventoryAppraisalService $service,
    ): JsonResponse|RedirectResponse|View {
        try {
            $completed = $service->appraise(
                $program,
                (int) $request->user()->getAuthIdentifier(),
                (string) $request->validated('inventory'),
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'appraisal_token' => $completed->token,
                    'appraisal' => $completed->result->toArray(),
                ], 201);
            }

            $counts = $completed->result->summaryCounts();

            return view('seat-buyback-programs::appraisals.result', [
                'appraisalToken' => $completed->token,
                'appraisal' => $completed->result,
                'program' => $program,
                'counts' => $counts,
                'resolvedCount' => $counts['PRICED'] + $counts['EXCLUDED']
                    + $counts['UNPRICED'] + $counts['REFERENCE_UNAVAILABLE'],
                'linesByStatus' => collect($completed->result->lines)
                    ->groupBy(static fn ($line): string => $line->status->value),
            ]);
        } catch (ProgramUnavailableForAppraisalException $exception) {
            return $this->failure($request, $program, 'PROGRAM_UNAVAILABLE', $exception->getMessage(), 409,
                'This Buyback Program is currently unavailable.');
        } catch (AppraisalLimitExceededException $exception) {
            return $this->failure($request, $program, 'APPRAISAL_LIMIT_EXCEEDED', $exception->getMessage(), 413,
                $exception->getMessage());
        } catch (AppraisalInputException $exception) {
            return $this->failure($request, $program, 'INVALID_INPUT', $exception->getMessage(), 422,
                'The pasted item list could not be appraised. Check the input and try again.');
        } catch (CompressionReferenceDataUnavailableException $exception) {
            return $this->failure($request, $program, 'COMPRESSION_REFERENCE_DATA_UNAVAILABLE', $exception->getMessage(), 503,
                'Required item reference data is temporarily unavailable.');
        } catch (AppraisalPolicyException $exception) {
            return $this->failure($request, $program, 'INVALID_EFFECTIVE_POLICY', $exception->getMessage(), 422,
                'This Buyback Program cannot currently calculate a valid policy.');
        } catch (AppraisalPricingConfigurationException $exception) {
            return $this->failure($request, $program, 'REFERENCE_MISCONFIGURED', $exception->getMessage(), 422,
                'This Buyback Program\'s price reference is currently unavailable.');
        } catch (AppraisalStorageException|AppraisalInfrastructureException $exception) {
            Log::error('Buyback appraisal infrastructure failure.', [
                'program_id' => $program->getKey(),
                'exception' => $exception,
            ]);

            return $this->failure($request, $program, 'APPRAISAL_UNAVAILABLE', 'The appraisal could not be completed.', 503,
                'The appraisal could not be completed. Please try again later.');
        } catch (Throwable $exception) {
            Log::error('Unexpected buyback appraisal failure.', [
                'program_id' => $program->getKey(),
                'exception' => $exception,
            ]);

            return $this->failure($request, $program, 'APPRAISAL_UNAVAILABLE', 'The appraisal could not be completed.', 503,
                'The appraisal could not be completed. Please try again later.');
        }
    }

    private function failure(
        CreateAppraisalRequest $request,
        BuybackProgram $program,
        string $code,
        string $message,
        int $status,
        string $browserMessage,
    ): JsonResponse|RedirectResponse {
        if (! $request->expectsJson()) {
            return redirect()
                ->route('buyback.appraisals.create', $program)
                ->withInput()
                ->withErrors(['appraisal' => $browserMessage]);
        }

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
