<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Infrastructure\Appraisal;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use RandulfTheGrey\Seat\BuybackPrograms\Contracts\AppraisalStore;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\StoredAppraisal;
use RandulfTheGrey\Seat\BuybackPrograms\Exceptions\AppraisalStorageException;
use Throwable;

final class CacheAppraisalStore implements AppraisalStore
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function put(AppraisalResult $result): string
    {
        if ($result->quoteExpiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw new AppraisalStorageException('The appraisal has no remaining quote validity.');
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            throw new AppraisalStorageException('An opaque appraisal token could not be generated.', previous: $exception);
        }
        $payload = [
            'schema_version' => 1,
            'owner_user_id' => $result->requesterUserId,
            'program_id' => $result->programId,
            'priced_at' => $result->pricedAt->toIso8601String(),
            'quote_expires_at' => $result->quoteExpiresAt->toIso8601String(),
            'consumption' => [
                'state' => 'AVAILABLE',
                'created_quote_id' => null,
            ],
            'result' => $result->toArray(),
        ];

        try {
            $stored = $this->cache->put($this->key($token), $payload, $result->quoteExpiresAt);
        } catch (Throwable $exception) {
            throw new AppraisalStorageException('The trusted appraisal could not be cached.', previous: $exception);
        }

        if (! $stored) {
            throw new AppraisalStorageException('The trusted appraisal could not be cached.');
        }

        return $token;
    }

    public function findForRequester(string $token, int $requesterUserId): ?StoredAppraisal
    {
        $stored = $this->find($token);

        if (
            $stored === null
            || $stored->result->requesterUserId !== $requesterUserId
            || $stored->result->quoteExpiresAt->lessThanOrEqualTo(CarbonImmutable::now())
        ) {
            return null;
        }

        return $stored;
    }

    public function find(string $token): ?StoredAppraisal
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }

        try {
            $payload = $this->cache->get($this->key($token));

            if (
                ! is_array($payload)
                || ($payload['schema_version'] ?? null) !== 1
            ) {
                return null;
            }

            $result = AppraisalResult::fromArray((array) ($payload['result'] ?? []));

            if (
                $result->requesterUserId !== (int) ($payload['owner_user_id'] ?? 0)
                || $result->programId !== (int) ($payload['program_id'] ?? 0)
                || $result->pricedAt->toIso8601String() !== ($payload['priced_at'] ?? null)
                || $result->quoteExpiresAt->toIso8601String() !== ($payload['quote_expires_at'] ?? null)
            ) {
                return null;
            }

            $consumption = (array) ($payload['consumption'] ?? []);
            $createdQuoteId = $consumption['created_quote_id'] ?? null;

            return new StoredAppraisal(
                $token,
                $result,
                $createdQuoteId === null ? null : (string) $createdQuoteId,
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function recordCreatedQuote(string $token, int $requesterUserId, string $quotePublicId): void
    {
        $stored = $this->find($token);

        if ($stored === null || $stored->result->requesterUserId !== $requesterUserId) {
            throw new AppraisalStorageException('The appraisal token cannot be coordinated with the Quote.');
        }

        $key = $this->key($token);
        $payload = $this->cache->get($key);

        if (! is_array($payload)) {
            throw new AppraisalStorageException('The appraisal token cannot be coordinated with the Quote.');
        }

        $existingQuoteId = data_get($payload, 'consumption.created_quote_id');

        if ($existingQuoteId !== null && ! hash_equals((string) $existingQuoteId, $quotePublicId)) {
            throw new AppraisalStorageException('The appraisal token is already coordinated with another Quote.');
        }

        $payload['consumption'] = [
            'state' => 'QUOTED',
            'created_quote_id' => $quotePublicId,
        ];

        try {
            $storedSuccessfully = $this->cache->put($key, $payload, $stored->result->quoteExpiresAt);
        } catch (Throwable $exception) {
            throw new AppraisalStorageException(
                'The appraisal token could not be coordinated with the Quote.',
                previous: $exception,
            );
        }

        if (! $storedSuccessfully) {
            throw new AppraisalStorageException('The appraisal token could not be coordinated with the Quote.');
        }
    }

    public function withCreationLock(string $token, Closure $callback): mixed
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new AppraisalStorageException('The appraisal token cannot be locked for Quote creation.');
        }

        $seconds = max(1, (int) config('seat-buyback-programs.appraisal.creation_lock_seconds', 15));
        $waitSeconds = max(1, (int) config('seat-buyback-programs.appraisal.creation_lock_wait_seconds', 5));

        try {
            $lock = $this->cache->lock($this->key($token) . ':quote-creation-lock', $seconds);
        } catch (Throwable $exception) {
            throw new AppraisalStorageException(
                'The appraisal cache does not support Quote-creation locking.',
                previous: $exception,
            );
        }

        try {
            return $lock->block($waitSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            throw new AppraisalStorageException(
                'Quote creation is already in progress for this appraisal token.',
                previous: $exception,
            );
        }
    }

    private function key(string $token): string
    {
        $prefix = (string) config(
            'seat-buyback-programs.appraisal.cache_prefix',
            'seat-buyback-programs:appraisal:',
        );

        return $prefix . hash('sha256', $token);
    }
}
