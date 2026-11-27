<?php

use App\Domains\Auth\WebSessionService;
use App\Models\CustomerWebSession;
use App\Models\User;

if (! function_exists('accountingActAs')) {
    /**
     * Fresh authenticated Branch Panel session; safe to call before each request.
     *
     * @param  array<string, mixed>  $ctx
     */
    function accountingActAs(array $ctx, User $user): void
    {
        test()->flushSession();
        CustomerWebSession::query()->where('user_id', $user->id)->delete();
        test()->actingAs($user);
        session()->forget(WebSessionService::SESSION_VERSION_KEY);
        seedBranchSession($ctx['company'], $ctx['branch']);
    }
}
