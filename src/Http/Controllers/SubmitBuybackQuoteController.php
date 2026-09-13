<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\SubmitBuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidRequestFieldException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuoteExpiredException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuoteOwnershipException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestPersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\SubmitBuybackQuoteRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Resources\RequesterBuybackRequestResource;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use Throwable;

final class SubmitBuybackQuoteController
{
    public function __invoke(
        SubmitBuybackQuoteRequest $request,
        BuybackQuote $quote,
        SubmitBuybackQuote $service,
    ): JsonResponse|RedirectResponse {
        try {
            $contractId = $request->validated('eve_contract_id');
            $buybackRequest = $service->submit(
                $quote,
                (int) $request->user()->getAuthIdentifier(),
                $contractId === null ? null : (int) $contractId,
                $request->validated('requester_note') === null
                    ? null
                    : (string) $request->validated('requester_note'),
            );

            if (! $request->expectsJson()) {
                return redirect()
                    ->route('buyback.requests.show', $buybackRequest)
                    ->with('success', 'Your Buyback Request has been submitted.');
            }

            return response()->json([
                'request' => (new RequesterBuybackRequestResource($buybackRequest))->resolve($request),
            ], $buybackRequest->wasRecentlyCreated ? 201 : 200);
        } catch (QuoteOwnershipException $exception) {
            if (! $request->expectsJson()) {
                abort(404);
            }

            return $this->failure($request, $quote, 'QUOTE_OWNERSHIP_MISMATCH', $exception->getMessage(), 403,
                'The Quote could not be submitted.');
        } catch (QuoteExpiredException $exception) {
            return $this->failure($request, $quote, 'QUOTE_EXPIRED', $exception->getMessage(), 409,
                'This Quote has expired. Run a new appraisal for current pricing.');
        } catch (InvalidRequestFieldException $exception) {
            return $this->failure($request, $quote, 'INVALID_REQUEST_FIELD', $exception->getMessage(), 422,
                $exception->getMessage());
        } catch (RequestPersistenceException $exception) {
            Log::error('Buyback Request submission failed.', ['exception' => $exception]);

            return $this->failure($request, $quote, 'REQUEST_UNAVAILABLE', 'The Buyback Request could not be submitted.', 503,
                'The Buyback Request could not be submitted. Please try again later.');
        } catch (Throwable $exception) {
            Log::error('Unexpected Buyback Request submission failure.', ['exception' => $exception]);

            return $this->failure($request, $quote, 'REQUEST_UNAVAILABLE', 'The Buyback Request could not be submitted.', 503,
                'The Buyback Request could not be submitted. Please try again later.');
        }
    }

    private function failure(
        SubmitBuybackQuoteRequest $request,
        BuybackQuote $quote,
        string $code,
        string $message,
        int $status,
        string $browserMessage,
    ): JsonResponse|RedirectResponse {
        if (! $request->expectsJson()) {
            return redirect()
                ->route('buyback.quotes.show', $quote)
                ->withInput()
                ->with('error', $browserMessage);
        }

        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
