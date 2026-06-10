<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait AuthenticatedUser
{
    protected function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
