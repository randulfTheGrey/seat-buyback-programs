<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Policies;

use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackQuote;

final class BuybackQuotePolicy
{
    public function view(object $user, BuybackQuote $quote): bool
    {
        return $this->hasPermission($user, 'randulfthegrey-buyback.request')
            && (int) $quote->requester_user_id === $this->userId($user);
    }

    public function submit(object $user, BuybackQuote $quote): bool
    {
        return $this->view($user, $quote);
    }

    private function hasPermission(object $user, string $permission): bool
    {
        return method_exists($user, 'can') && $user->can($permission) === true;
    }

    private function userId(object $user): int
    {
        return method_exists($user, 'getAuthIdentifier')
            ? (int) $user->getAuthIdentifier()
            : 0;
    }
}
