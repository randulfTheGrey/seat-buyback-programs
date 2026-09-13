<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\CompleteBuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\RejectBuybackRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\UpdateBuybackContract;
use RandulfTheGrey\Seat\BuybackPrograms\Application\Request\UpdateManagerNote;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidRequestFieldException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\MissingRejectionReasonException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestNotPendingException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestPersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\CompleteBuybackRequestRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\RejectBuybackRequestRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\UpdateManagerContractRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Requests\UpdateManagerNoteRequest;
use RandulfTheGrey\Seat\BuybackPrograms\Http\Resources\ManagerBuybackRequestResource;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use Throwable;

final class ManageBuybackRequestController
{
    public function contract(
        UpdateManagerContractRequest $request,
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
            'EVE contract ID updated.',
        );
    }

    public function managerNote(
        UpdateManagerNoteRequest $request,
        BuybackRequest $buybackRequest,
        UpdateManagerNote $service,
    ): JsonResponse|RedirectResponse {
        $note = $request->validated('manager_note');

        return $this->mutate(
            $request,
            static fn (): BuybackRequest => $service->update(
                $buybackRequest,
                $note === null ? null : (string) $note,
            ),
            'Internal manager note updated.',
        );
    }

    public function complete(
        CompleteBuybackRequestRequest $request,
        BuybackRequest $buybackRequest,
        CompleteBuybackRequest $service,
    ): JsonResponse|RedirectResponse {
        return $this->mutate(
            $request,
            static fn (): BuybackRequest => $service->complete(
                $buybackRequest,
                (int) $request->user()->getAuthIdentifier(),
            ),
            'Buyback Request completed.',
        );
    }

    public function reject(
        RejectBuybackRequestRequest $request,
        BuybackRequest $buybackRequest,
        RejectBuybackRequest $service,
    ): JsonResponse|RedirectResponse {
        return $this->mutate(
            $request,
            static fn (): BuybackRequest => $service->reject(
                $buybackRequest,
                (int) $request->user()->getAuthIdentifier(),
                (string) $request->validated('rejection_reason'),
            ),
            'Buyback Request rejected.',
        );
    }

    /** @param Closure(): BuybackRequest $operation */
    private function mutate(
        Request $request,
        Closure $operation,
        string $successMessage,
    ): JsonResponse|RedirectResponse
    {
        try {
            $buybackRequest = $operation();

            if (! $request->expectsJson()) {
                return redirect()
                    ->route('buyback.manage.requests.show', $buybackRequest)
                    ->with('success', $successMessage);
            }

            return response()->json([
                'request' => (new ManagerBuybackRequestResource($buybackRequest))->resolve($request),
            ]);
        } catch (RequestNotPendingException $exception) {
            return $this->failure($request, 'REQUEST_NOT_PENDING',
                'This Buyback Request is no longer pending.', 409);
        } catch (MissingRejectionReasonException|InvalidRequestFieldException $exception) {
            return $this->failure($request, 'INVALID_REQUEST_FIELD', $exception->getMessage(), 422);
        } catch (RequestPersistenceException $exception) {
            Log::error('Managed Buyback Request update failed.', ['exception' => $exception]);

            return $this->failure($request, 'REQUEST_UNAVAILABLE',
                'The Buyback Request could not be updated. Please try again later.', 503);
        } catch (Throwable $exception) {
            Log::error('Unexpected managed Buyback Request update failure.', ['exception' => $exception]);

            return $this->failure($request, 'REQUEST_UNAVAILABLE',
                'The Buyback Request could not be updated. Please try again later.', 503);
        }
    }

    private function failure(
        Request $request,
        string $code,
        string $message,
        int $status,
    ): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return redirect()
                ->route('buyback.manage.requests.show', $request->route('buybackRequest'))
                ->withInput()
                ->with('error', $message);
        }

        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
