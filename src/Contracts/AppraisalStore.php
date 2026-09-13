<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Contracts;

use Closure;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\AppraisalResult;
use RandulfTheGrey\Seat\BuybackPrograms\Domain\Appraisal\StoredAppraisal;

interface AppraisalStore
{
    public function put(AppraisalResult $result): string;

    public function find(string $token): ?StoredAppraisal;

    public function findForRequester(string $token, int $requesterUserId): ?StoredAppraisal;

    public function recordCreatedQuote(string $token, int $requesterUserId, string $quotePublicId): void;

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function withCreationLock(string $token, Closure $callback): mixed;
}
