<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\CancelBuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\UpdateBuybackContract;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\UpdateRequesterNote;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidRequestFieldException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestNotPendingException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestPersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\CancelBuybackRequestRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\UpdateRequesterContractRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\UpdateRequesterNoteRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Resources\RequesterBuybackRequestResource;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use Throwable;

final class RequesterBuybackRequestController
{
    public function contract(
        UpdateRequesterContractRequest $request,
        BuybackRequest $buybackRequest,
        UpdateBuybackContract $service,
    ): JsonResponse|RedirectResponse {
        $contractId = $request->validated('eve_contract_id');

        return $this->mutate(
            $request,
            static fn (): BuybackRequest => $service->update(
                $buybackRequest,
                $contractId === null ? null : (int) $contractId,
            ),
        );
    }

    public function requesterNote(
        UpdateRequesterNoteRequest $request,
        BuybackRequest $buybackRequest,
        UpdateRequesterNote $service,
    ): JsonResponse|RedirectResponse {
        $note = $request->validated('requester_note');

        return $this->mutate(
            $request,
            static fn (): BuybackRequest => $service->update(
                $buybackRequest,
                $note === null ? null : (string) $note,
            ),
        );
    }

    public function cancel(
        CancelBuybackRequestRequest $request,
        BuybackRequest $buybackRequest,
        CancelBuybackRequest $service,
    ): JsonResponse|RedirectResponse {
        return $this->mutate(
            $request,
            static fn (): BuybackRequest => $service->cancel(
                $buybackRequest,
                (int) $request->user()->getAuthIdentifier(),
            ),
        );
    }

    /** @param Closure(): BuybackRequest $operation */
    private function mutate(Request $request, Closure $operation): JsonResponse|RedirectResponse
    {
        try {
            $buybackRequest = $operation();

            if (! $request->expectsJson()) {
                return redirect()
                    ->route('buyback.requests.show', $buybackRequest)
                    ->with('success', 'Your Buyback Request has been updated.');
            }

            return response()->json([
                'request' => (new RequesterBuybackRequestResource($buybackRequest))->resolve($request),
            ]);
        } catch (RequestNotPendingException $exception) {
            return $this->failure($request, 'REQUEST_NOT_PENDING', $exception->getMessage(), 409,
                'This Buyback Request is no longer pending.');
        } catch (InvalidRequestFieldException $exception) {
            return $this->failure($request, 'INVALID_REQUEST_FIELD', $exception->getMessage(), 422,
                $exception->getMessage());
        } catch (RequestPersistenceException $exception) {
            Log::error('Buyback Request update failed.', ['exception' => $exception]);

            return $this->failure($request, 'REQUEST_UNAVAILABLE', 'The Buyback Request could not be updated.', 503,
                'The Buyback Request could not be updated. Please try again later.');
        } catch (Throwable $exception) {
            Log::error('Unexpected Buyback Request update failure.', ['exception' => $exception]);

            return $this->failure($request, 'REQUEST_UNAVAILABLE', 'The Buyback Request could not be updated.', 503,
                'The Buyback Request could not be updated. Please try again later.');
        }
    }

    private function failure(
        Request $request,
        string $code,
        string $message,
        int $status,
        string $browserMessage,
    ): JsonResponse|RedirectResponse {
        if (! $request->expectsJson()) {
            $buybackRequest = $request->route('buybackRequest');

            return redirect()
                ->route('buyback.requests.show', $buybackRequest)
                ->withInput()
                ->with('error', $browserMessage);
        }

        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
