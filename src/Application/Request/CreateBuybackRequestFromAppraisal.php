<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use RandulfTheGrey\Seat\BuybackPrograms\Application\Quote\CreateQuoteFromAppraisal;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestCreationFromAppraisalException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use Throwable;

final readonly class CreateBuybackRequestFromAppraisal
{
    public function __construct(
        private CreateQuoteFromAppraisal $createQuote,
        private SubmitBuybackQuote $submitQuote,
    ) {
    }

    public function create(
        string $appraisalToken,
        int $requesterUserId,
        string $requesterNameSnapshot,
    ): BuybackRequest {
        $quote = $this->createQuote->create(
            $appraisalToken,
            $requesterUserId,
            $requesterNameSnapshot,
        );

        try {
            return $this->submitQuote->submit($quote, $requesterUserId);
        } catch (Throwable $exception) {
            // A listener may fail after the Request transaction commits. Recover
            // the durable idempotent result before reporting a partial failure.
            try {
                $request = $quote->buybackRequest()->with('quote')->first();

                if ($request !== null) {
                    $request->wasRecentlyCreated = false;

                    return $request;
                }
            } catch (Throwable) {
                // Preserve the original submission failure for the caller.
            }

            throw new RequestCreationFromAppraisalException($quote, $exception);
        }
    }
}
