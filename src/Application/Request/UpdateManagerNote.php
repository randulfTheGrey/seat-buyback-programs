<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Application\Request;

use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class UpdateManagerNote
{
    public function __construct(private readonly PendingRequestMutation $mutation)
    {
    }

    public function update(BuybackRequest $request, ?string $note): BuybackRequest
    {
        return $this->mutation->apply($request, [
            'manager_note' => RequestFieldNormalizer::note($note),
        ]);
    }
}
