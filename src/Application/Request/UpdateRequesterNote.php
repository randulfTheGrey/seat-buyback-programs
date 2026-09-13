<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class UpdateRequesterNote
{
    public function __construct(private readonly PendingRequestMutation $mutation)
    {
    }

    public function update(BuybackRequest $request, ?string $note): BuybackRequest
    {
        return $this->mutation->apply($request, [
            'requester_note' => RequestFieldNormalizer::note($note),
        ]);
    }
}
