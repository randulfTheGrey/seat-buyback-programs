<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Quote\CreateQuoteFromAppraisal;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalExpiredException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalOwnershipException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalTokenNotFoundException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidAppraisalForQuoteException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\ProgramUnavailableForQuoteException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuotePersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\CreateQuoteRequest;
use Throwable;

final class QuoteController
{
    public function __invoke(
        CreateQuoteRequest $request,
        CreateQuoteFromAppraisal $service,
    ): JsonResponse|RedirectResponse {
        $requesterId = (int) $request->user()->getAuthIdentifier();
        $requesterName = trim((string) data_get($request->user(), 'name', ''));

        if ($requesterName === '') {
            $requesterName = 'User ' . $requesterId;
        }

        try {
            $quote = $service->create(
                (string) $request->validated('appraisal_token'),
                $requesterId,
                $requesterName,
            );

            if (! $request->expectsJson()) {
                return redirect()->route('buyback.quotes.show', $quote);
            }

            return response()->json([
                'quote' => [
                    'public_id' => $quote->public_id,
                    'quoted_at' => $quote->quoted_at->toIso8601String(),
                    'expires_at' => $quote->expires_at->toIso8601String(),
                    'payable_total' => $quote->payable_total,
                ],
            ], $quote->wasRecentlyCreated ? 201 : 200);
        } catch (AppraisalTokenNotFoundException $exception) {
            return $this->failure($request, 'APPRAISAL_NOT_FOUND', $exception->getMessage(), 404,
                'This appraisal is no longer available. Run a new appraisal.');
        } catch (AppraisalOwnershipException $exception) {
            if (! $request->expectsJson()) {
                abort(404);
            }

            return $this->failure($request, 'APPRAISAL_OWNERSHIP_MISMATCH', $exception->getMessage(), 403,
                'This appraisal is no longer available. Run a new appraisal.');
        } catch (AppraisalExpiredException $exception) {
            return $this->failure($request, 'APPRAISAL_EXPIRED', $exception->getMessage(), 409,
                'This appraisal is no longer available. Run a new appraisal.');
        } catch (ProgramUnavailableForQuoteException $exception) {
            return $this->failure($request, 'PROGRAM_UNAVAILABLE', $exception->getMessage(), 409,
                'This Buyback Program is currently unavailable.');
        } catch (InvalidAppraisalForQuoteException $exception) {
            return $this->failure($request, 'INVALID_APPRAISAL', $exception->getMessage(), 422,
                'This appraisal cannot create a Quote. Run a new appraisal.');
        } catch (QuotePersistenceException $exception) {
            Log::error('Buyback Quote creation failed.', ['exception' => $exception]);

            return $this->failure($request, 'QUOTE_UNAVAILABLE', 'The Quote could not be created.', 503,
                'The Quote could not be created. Please try again later.');
        } catch (Throwable $exception) {
            Log::error('Unexpected Buyback Quote creation failure.', ['exception' => $exception]);

            return $this->failure($request, 'QUOTE_UNAVAILABLE', 'The Quote could not be created.', 503,
                'The Quote could not be created. Please try again later.');
        }
    }

    private function failure(
        CreateQuoteRequest $request,
        string $code,
        string $message,
        int $status,
        string $browserMessage,
    ): JsonResponse|RedirectResponse {
        if (! $request->expectsJson()) {
            return redirect()
                ->route('buyback.programs.index')
                ->with('error', $browserMessage);
        }

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
