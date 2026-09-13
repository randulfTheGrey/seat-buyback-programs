<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Quote;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\AppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalLine;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalResult;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\AppraisalLineStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Enums\ProgramStatus;
use RandulfTheGrey\Seat\BuybackPrograms\Events\BuybackQuoteCreated;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalExpiredException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalOwnershipException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalStorageException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalTokenNotFoundException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\InvalidAppraisalForQuoteException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\ProgramUnavailableForQuoteException;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\QuotePersistenceException;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;
use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;
use Throwable;
use ValueError;

final class CreateQuoteFromAppraisal
{
    public function __construct(
        private readonly AppraisalStore $appraisals,
        private readonly Dispatcher $events,
    ) {
    }

    public function create(
        string $token,
        int $requesterUserId,
        string $requesterNameSnapshot,
    ): BuybackQuote {
        if ($requesterUserId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new AppraisalTokenNotFoundException('The appraisal token was not found.');
        }

        try {
            return $this->appraisals->withCreationLock(
                $token,
                fn (): BuybackQuote => $this->createWhileLocked(
                    $token,
                    $requesterUserId,
                    $requesterNameSnapshot,
                ),
            );
        } catch (AppraisalStorageException $exception) {
            throw new QuotePersistenceException(
                'The Quote could not be created at this time.',
                previous: $exception,
            );
        }
    }

    private function createWhileLocked(
        string $token,
        int $requesterUserId,
        string $requesterNameSnapshot,
    ): BuybackQuote {
        $tokenHash = hash('sha256', $token);
        $existing = $this->existingQuote($tokenHash, $requesterUserId);

        if ($existing !== null) {
            $this->coordinateCache($token, $requesterUserId, $existing);

            return $existing->load('items');
        }

        $stored = $this->appraisals->find($token);

        if ($stored === null) {
            throw new AppraisalTokenNotFoundException('The appraisal token was not found or has expired.');
        }

        $appraisal = $stored->result;

        if ($appraisal->requesterUserId !== $requesterUserId) {
            throw new AppraisalOwnershipException('The appraisal token belongs to another requester.');
        }

        if ($stored->createdQuoteId !== null) {
            $coordinatedQuote = BuybackQuote::query()
                ->where('public_id', $stored->createdQuoteId)
                ->first();

            if (
                $coordinatedQuote === null
                || ! hash_equals($tokenHash, (string) $coordinatedQuote->appraisal_token_hash)
            ) {
                throw new InvalidAppraisalForQuoteException(
                    'The appraisal token has inconsistent Quote-consumption metadata.',
                );
            }

            if ((int) $coordinatedQuote->requester_user_id !== $requesterUserId) {
                throw new AppraisalOwnershipException('The appraisal token belongs to another requester.');
            }

            return $coordinatedQuote->load('items');
        }

        if ($appraisal->quoteExpiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new AppraisalExpiredException('The appraisal has expired; create a new appraisal.');
        }

        $requesterNameSnapshot = trim($requesterNameSnapshot);

        if ($requesterNameSnapshot === '' || strlen($requesterNameSnapshot) > 255) {
            throw new InvalidAppraisalForQuoteException('The requester identity snapshot is invalid.');
        }

        if (
            trim($appraisal->programName) === ''
            || strlen($appraisal->programName) > 255
            || $appraisal->pricedAt->greaterThanOrEqualTo($appraisal->quoteExpiresAt)
        ) {
            throw new InvalidAppraisalForQuoteException('The appraisal timing or Program snapshot is invalid.');
        }

        [$items, $payableTotal] = $this->preparePayableItems($appraisal);

        try {
            $quote = DB::transaction(function () use (
                $appraisal,
                $items,
                $payableTotal,
                $requesterNameSnapshot,
                $tokenHash,
            ): BuybackQuote {
                if ($appraisal->quoteExpiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
                    throw new AppraisalExpiredException(
                        'The appraisal has expired; create a new appraisal.',
                    );
                }

                $program = BuybackProgram::query()
                    ->lockForUpdate()
                    ->find($appraisal->programId);

                try {
                    $enabled = $program?->status === ProgramStatus::ENABLED;
                } catch (ValueError) {
                    $enabled = false;
                }

                if (! $enabled) {
                    throw new ProgramUnavailableForQuoteException(
                        'The Buyback Program is no longer enabled for Quote creation.',
                    );
                }

                $quote = BuybackQuote::create([
                    'appraisal_token_hash' => $tokenHash,
                    'program_id' => $program->getKey(),
                    'requester_user_id' => $appraisal->requesterUserId,
                    'requester_name_snapshot' => $requesterNameSnapshot,
                    'program_name_snapshot' => $appraisal->programName,
                    'pricing_completed_at' => $appraisal->pricedAt,
                    'quoted_at' => $appraisal->pricedAt,
                    'expires_at' => $appraisal->quoteExpiresAt,
                    'payable_total' => $payableTotal,
                ]);

                $quote->items()->createMany($items);

                return $quote;
            }, 3);
        } catch (AppraisalExpiredException|ProgramUnavailableForQuoteException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            $existing = $this->existingQuote($tokenHash, $requesterUserId);

            if ($existing !== null) {
                $this->coordinateCache($token, $requesterUserId, $existing);

                return $existing->load('items');
            }

            throw new QuotePersistenceException(
                'The Quote and all payable items could not be persisted.',
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new QuotePersistenceException(
                'The Quote and all payable items could not be persisted.',
                previous: $exception,
            );
        }

        $this->coordinateCache($token, $requesterUserId, $quote);

        $this->events->dispatch(new BuybackQuoteCreated(
            quoteId: (int) $quote->getKey(),
            quotePublicId: (string) $quote->public_id,
            programId: (int) $quote->program_id,
            requesterUserId: (int) $quote->requester_user_id,
        ));

        return $quote->load('items');
    }

    private function existingQuote(string $tokenHash, int $requesterUserId): ?BuybackQuote
    {
        $quote = BuybackQuote::query()
            ->where('appraisal_token_hash', $tokenHash)
            ->first();

        if ($quote !== null && (int) $quote->requester_user_id !== $requesterUserId) {
            throw new AppraisalOwnershipException('The appraisal token belongs to another requester.');
        }

        return $quote;
    }

    /**
     * @return array{list<array<string, mixed>>, string}
     */
    private function preparePayableItems(AppraisalResult $appraisal): array
    {
        $items = [];
        $typeIds = [];
        $total = BigDecimal::of('0.00');

        foreach ($appraisal->lines as $line) {
            if ($line->status !== AppraisalLineStatus::PRICED) {
                continue;
            }

            $item = $this->preparePayableItem($line);

            if (isset($typeIds[$item['type_id']])) {
                throw new InvalidAppraisalForQuoteException(
                    'The trusted appraisal contains a duplicate payable type.',
                );
            }

            $typeIds[$item['type_id']] = true;
            $items[] = $item;
            $total = $total->plus($item['line_total']);
        }

        if ($items === []) {
            throw new InvalidAppraisalForQuoteException('The appraisal contains no payable lines.');
        }

        try {
            $trustedTotal = BigDecimal::of($appraisal->quoteableTotal)
                ->toScale(2, RoundingMode::UNNECESSARY);
        } catch (MathException|InvalidArgumentException $exception) {
            throw new InvalidAppraisalForQuoteException(
                'The trusted appraisal total is invalid.',
                previous: $exception,
            );
        }

        $total = $total->toScale(2, RoundingMode::UNNECESSARY);

        if ($trustedTotal->isLessThan(0) || ! $total->isEqualTo($trustedTotal)) {
            throw new InvalidAppraisalForQuoteException(
                'The trusted appraisal total does not match its payable lines.',
            );
        }

        return [$items, (string) $total];
    }

    /** @return array<string, mixed> */
    private function preparePayableItem(AppraisalLine $line): array
    {
        if (
            $line->typeId === null
            || $line->typeId <= 0
            || $line->typeName === null
            || trim($line->typeName) === ''
            || strlen($line->typeName) > 255
            || $line->quantity === null
            || $line->quantity <= 0
            || $line->compressionState === null
            || $line->logicalReferenceMode === null
            || $line->referenceResolution === null
            || $line->referenceUnitPrice === null
            || $line->effectiveModifierBps === null
            || $line->effectiveModifierBps < -10000
            || $line->effectiveModifierBps > 10000
            || $line->finalUnitPrice === null
            || $line->lineTotal === null
            || ! $this->validSnapshot($line->effectivePolicy)
            || ! $this->validSnapshot($line->pricingProvenance)
            || ($line->effectivePolicy['reference_mode'] ?? null) !== $line->logicalReferenceMode->value
            || ($line->effectivePolicy['effective_modifier_bps'] ?? null) !== $line->effectiveModifierBps
            || ($line->pricingProvenance['logical_mode'] ?? null) !== $line->logicalReferenceMode->value
            || ($line->pricingProvenance['resolution'] ?? null) !== $line->referenceResolution->value
        ) {
            throw new InvalidAppraisalForQuoteException(
                'A payable appraisal line has incomplete or inconsistent historical evidence.',
            );
        }

        try {
            $referenceUnitPrice = BigDecimal::of($line->referenceUnitPrice)
                ->toScale(18, RoundingMode::UNNECESSARY);
            $finalUnitPrice = BigDecimal::of($line->finalUnitPrice)
                ->toScale(2, RoundingMode::UNNECESSARY);
            $lineTotal = BigDecimal::of($line->lineTotal)
                ->toScale(2, RoundingMode::UNNECESSARY);
            $expectedLineTotal = $finalUnitPrice
                ->multipliedBy($line->quantity)
                ->toScale(2, RoundingMode::UNNECESSARY);
        } catch (MathException|InvalidArgumentException $exception) {
            throw new InvalidAppraisalForQuoteException(
                'A payable appraisal line contains invalid exact-decimal values.',
                previous: $exception,
            );
        }

        if (
            $referenceUnitPrice->isLessThan(0)
            || $finalUnitPrice->isLessThan(0)
            || $lineTotal->isLessThan(0)
            || ! $lineTotal->isEqualTo($expectedLineTotal)
        ) {
            throw new InvalidAppraisalForQuoteException(
                'A payable appraisal line contains inconsistent monetary values.',
            );
        }

        return [
            'type_id' => $line->typeId,
            'type_name' => $line->typeName,
            'quantity' => $line->quantity,
            'compression_state' => $line->compressionState,
            'reference_mode' => $line->logicalReferenceMode,
            'reference_resolution' => $line->referenceResolution,
            'reference_unit_price' => (string) $referenceUnitPrice,
            'effective_modifier_bps' => $line->effectiveModifierBps,
            'final_unit_price' => (string) $finalUnitPrice,
            'line_total' => (string) $lineTotal,
            'policy_snapshot' => $line->effectivePolicy,
            'pricing_snapshot' => $line->pricingProvenance,
        ];
    }

    /** @param array<string, mixed>|null $snapshot */
    private function validSnapshot(?array $snapshot): bool
    {
        return $snapshot !== null && ($snapshot['schema_version'] ?? null) === 1;
    }

    private function coordinateCache(string $token, int $requesterUserId, BuybackQuote $quote): void
    {
        try {
            $this->appraisals->recordCreatedQuote($token, $requesterUserId, (string) $quote->public_id);
        } catch (Throwable $exception) {
            Log::warning('A persisted Buyback Quote could not update appraisal cache coordination.', [
                'quote_public_id' => $quote->public_id,
                'exception' => $exception,
            ]);
        }
    }
}
