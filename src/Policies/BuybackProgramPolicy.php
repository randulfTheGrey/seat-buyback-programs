<?php

declare(strict_types=1);

namespace RandulfTheGrey\Seat\BuybackPrograms\Policies;

use RandulfTheGrey\Seat\BuybackPrograms\Models\BuybackProgram;

final class BuybackProgramPolicy
{
    public function viewAny(object $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(object $user, BuybackProgram $program): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(object $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(object $user, BuybackProgram $program): bool
    {
        return $this->isAdministrator($user);
    }

    public function archive(object $user, BuybackProgram $program): bool
    {
        return $this->isAdministrator($user);
    }

    private function isAdministrator(object $user): bool
    {
        return method_exists($user, 'can') && $user->can('randulfthegrey-buyback.admin') === true;
    }
}
