<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->role === UserRole::ADMIN;
    }

    public function complete(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }
}