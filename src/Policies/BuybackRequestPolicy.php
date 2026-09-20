<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Policies;

use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackRequest;

final class BuybackRequestPolicy
{
    public function viewAsRequester(object $user, BuybackRequest $request): bool
    {
        return $this->isRequesterOwner($user, $request);
    }

    public function updateRequesterContract(object $user, BuybackRequest $request): bool
    {
        return $request->isPending() && $this->isRequesterOwner($user, $request);
    }

    public function updateRequesterNote(object $user, BuybackRequest $request): bool
    {
        return $request->isPending() && $this->isRequesterOwner($user, $request);
    }

    public function cancel(object $user, BuybackRequest $request): bool
    {
        return $request->isPending() && $this->isRequesterOwner($user, $request);
    }

    public function viewAsManager(object $user, BuybackRequest $request): bool
    {
        return $this->hasPermission($user, 'randulfthegrey-buyback.manage');
    }

    public function updateManagerContract(object $user, BuybackRequest $request): bool
    {
        return $request->isPending() && $this->hasPermission($user, 'randulfthegrey-buyback.manage');
    }

    public function updateManagerNote(object $user, BuybackRequest $request): bool
    {
        return $request->isPending() && $this->hasPermission($user, 'randulfthegrey-buyback.manage');
    }

    public function complete(object $user, BuybackRequest $request): bool
    {
        return $request->isPending() && $this->hasPermission($user, 'randulfthegrey-buyback.manage');
    }

    public function reject(object $user, BuybackRequest $request): bool
    {
        return $request->isPending() && $this->hasPermission($user, 'randulfthegrey-buyback.manage');
    }

    private function isRequesterOwner(object $user, BuybackRequest $request): bool
    {
        if (! $this->hasPermission($user, 'randulfthegrey-buyback.request')) {
            return false;
        }

        $userId = $this->userId($user);

        if ($userId <= 0) {
            return false;
        }

        if ($request->relationLoaded('quote')) {
            return (int) $request->quote->requester_user_id === $userId;
        }

        return $request->quote()
            ->where('requester_user_id', $userId)
            ->exists();
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
