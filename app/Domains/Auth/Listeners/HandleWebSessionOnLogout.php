<?php

namespace App\Domains\Auth\Listeners;

use App\Domains\Auth\WebSessionService;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

final class HandleWebSessionOnLogout
{
    public function __construct(
        private readonly WebSessionService $webSessions,
    ) {}

    public function handle(Logout $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $sessionId = session()->getId();

        $this->webSessions->handleLogout($user, $sessionId);
    }
}
