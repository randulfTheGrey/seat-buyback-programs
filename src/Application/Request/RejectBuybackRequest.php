<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestRejected;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidRequestFieldException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\MissingRejectionReasonException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class RejectBuybackRequest
{
    public function __construct(
        private readonly PendingRequestMutation $mutation,
        private readonly Dispatcher $events,
    ) {
    }

    public function reject(
        BuybackRequest $request,
        int $managerUserId,
        string $reason,
    ): BuybackRequest {
        if ($managerUserId <= 0) {
            throw new InvalidRequestFieldException('The rejecting manager is invalid.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new MissingRejectionReasonException('A rejection reason is required.');
        }

        $request = $this->mutation->apply($request, [
            'status' => BuybackRequestStatus::REJECTED->value,
            'rejection_reason' => $reason,
            'rejected_at' => CarbonImmutable::now(),
            'rejected_by_user_id' => $managerUserId,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'canceled_at' => null,
            'canceled_by_user_id' => null,
        ]);

        $this->events->dispatch(new BuybackRequestRejected(
            requestId: (int) $request->getKey(),
            requestPublicId: (string) $request->public_id,
            quotePublicId: (string) $request->quote->public_id,
            rejectedByUserId: $managerUserId,
        ));

        return $request;
    }
}
