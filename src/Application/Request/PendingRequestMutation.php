<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestNotPendingException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestPersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use Throwable;

final class PendingRequestMutation
{
    /** @param array<string, mixed> $attributes */
    public function apply(BuybackRequest $request, array $attributes): BuybackRequest
    {
        try {
            return DB::transaction(function () use ($request, $attributes): BuybackRequest {
                $updated = BuybackRequest::query()
                    ->whereKey($request->getKey())
                    ->where('status', BuybackRequestStatus::PENDING->value)
                    ->update($attributes + ['updated_at' => now()]);

                if ($updated !== 1) {
                    throw new RequestNotPendingException('The Buyback Request is no longer pending.');
                }

                return BuybackRequest::query()
                    ->with('quote')
                    ->findOrFail($request->getKey());
            }, 3);
        } catch (RequestNotPendingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Buyback Request persistence failed.', [
                'request_id' => $request->getKey(),
                'exception' => $exception,
            ]);

            throw new RequestPersistenceException(
                'The Buyback Request could not be updated at this time.',
                previous: $exception,
            );
        }
    }
}
