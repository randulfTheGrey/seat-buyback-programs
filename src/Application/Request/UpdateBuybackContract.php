<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class UpdateBuybackContract
{
    public function __construct(private readonly PendingRequestMutation $mutation)
    {
    }

    public function update(BuybackRequest $request, ?int $eveContractId): BuybackRequest
    {
        return $this->mutation->apply($request, [
            'eve_contract_id' => RequestFieldNormalizer::contractId($eveContractId),
        ]);
    }
}
