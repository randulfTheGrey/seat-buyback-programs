<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\BuybackRequestStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackRequestSubmitted;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuoteExpiredException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuoteOwnershipException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\RequestPersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;
use Throwable;

final class SubmitBuybackQuote
{
    public function __construct(private readonly Dispatcher $events)
    {
    }

    public function submit(
        BuybackQuote $quote,
        int $requesterUserId,
        ?int $eveContractId = null,
        ?string $requesterNote = null,
    ): BuybackRequest {
        if ($requesterUserId <= 0 || (int) $quote->requester_user_id !== $requesterUserId) {
            throw new QuoteOwnershipException('The Quote belongs to another requester.');
        }

        $eveContractId = RequestFieldNormalizer::contractId($eveContractId);
        $requesterNote = RequestFieldNormalizer::note($requesterNote);

        try {
            $existing = $this->existingRequest($quote);
        } catch (Throwable $exception) {
            $this->persistenceFailure($quote, $exception);
        }

        if ($existing !== null) {
            return $existing;
        }

        try {
            $request = DB::transaction(function () use (
                $quote,
                $requesterUserId,
                $eveContractId,
                $requesterNote,
            ): BuybackRequest {
                $lockedQuote = BuybackQuote::query()
                    ->lockForUpdate()
                    ->findOrFail($quote->getKey());

                if ((int) $lockedQuote->requester_user_id !== $requesterUserId) {
                    throw new QuoteOwnershipException('The Quote belongs to another requester.');
                }

                $existing = $this->existingRequest($lockedQuote);

                if ($existing !== null) {
                    return $existing;
                }

                if ($lockedQuote->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
                    throw new QuoteExpiredException('The Quote has expired; create a new appraisal.');
                }

                return BuybackRequest::create([
                    'quote_id' => $lockedQuote->getKey(),
                    'status' => BuybackRequestStatus::PENDING,
                    'submitted_at' => CarbonImmutable::now(),
                    'eve_contract_id' => $eveContractId,
                    'requester_note' => $requesterNote,
                ])->load('quote');
            }, 3);
        } catch (QuoteExpiredException|QuoteOwnershipException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            try {
                $existing = $this->existingRequest($quote);
            } catch (Throwable $lookupException) {
                $this->persistenceFailure($quote, $lookupException);
            }

            if ($existing !== null) {
                return $existing;
            }

            $this->persistenceFailure($quote, $exception);
        } catch (Throwable $exception) {
            $this->persistenceFailure($quote, $exception);
        }

        if ($request->wasRecentlyCreated) {
            $this->events->dispatch(new BuybackRequestSubmitted(
                requestId: (int) $request->getKey(),
                requestPublicId: (string) $request->public_id,
                quoteId: (int) $request->quote_id,
                quotePublicId: (string) $request->quote->public_id,
                requesterUserId: $requesterUserId,
            ));
        }

        return $request;
    }

    private function existingRequest(BuybackQuote $quote): ?BuybackRequest
    {
        $request = BuybackRequest::query()
            ->with('quote')
            ->where('quote_id', $quote->getKey())
            ->first();

        if ($request !== null) {
            $request->wasRecentlyCreated = false;
        }

        return $request;
    }

    private function persistenceFailure(BuybackQuote $quote, Throwable $exception): never
    {
        Log::error('Buyback Request submission failed.', [
            'quote_id' => $quote->getKey(),
            'exception' => $exception,
        ]);

        throw new RequestPersistenceException(
            'The Buyback Request could not be submitted at this time.',
            previous: $exception,
        );
    }
}
