<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestCanceled;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidRequestFieldException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class CancelBuybackRequest
{
    public function __construct(
        private readonly PendingRequestMutation $mutation,
        private readonly Dispatcher $events,
    ) {
    }

    public function cancel(BuybackRequest $request, int $requesterUserId): BuybackRequest
    {
        if ($requesterUserId <= 0) {
            throw new InvalidRequestFieldException('The canceling requester is invalid.');
        }

        $request = $this->mutation->apply($request, [
            'status' => BuybackRequestStatus::CANCELED->value,
            'canceled_at' => CarbonImmutable::now(),
            'canceled_by_user_id' => $requesterUserId,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'rejected_at' => null,
            'rejected_by_user_id' => null,
            'rejection_reason' => null,
        ]);

        $this->events->dispatch(new BuybackRequestCanceled(
            requestId: (int) $request->getKey(),
            requestPublicId: (string) $request->public_id,
            quotePublicId: (string) $request->quote->public_id,
            canceledByUserId: $requesterUserId,
        ));

        return $request;
    }
}
