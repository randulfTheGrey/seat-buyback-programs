<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestCompleted;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidRequestFieldException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class CompleteBuybackRequest
{
    public function __construct(
        private readonly PendingRequestMutation $mutation,
        private readonly Dispatcher $events,
    ) {
    }

    public function complete(BuybackRequest $request, int $managerUserId): BuybackRequest
    {
        if ($managerUserId <= 0) {
            throw new InvalidRequestFieldException('The completing manager is invalid.');
        }

        $request = $this->mutation->apply($request, [
            'status' => BuybackRequestStatus::COMPLETED->value,
            'completed_at' => CarbonImmutable::now(),
            'completed_by_user_id' => $managerUserId,
            'rejected_at' => null,
            'rejected_by_user_id' => null,
            'rejection_reason' => null,
            'canceled_at' => null,
            'canceled_by_user_id' => null,
        ]);

        $this->events->dispatch(new BuybackRequestCompleted(
            requestId: (int) $request->getKey(),
            requestPublicId: (string) $request->public_id,
            quotePublicId: (string) $request->quote->public_id,
            completedByUserId: $managerUserId,
        ));

        return $request;
    }
}
