<?php

namespace App\Domains\Auth\Http\Responses;

use App\Domains\Auth\PostLoginLandingResolver;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single Fortify post-login / 2FA response backed by PostLoginLandingResolver.
 */
final class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function __construct(
        private readonly PostLoginLandingResolver $resolver,
    ) {}

    public function toResponse($request): Response
    {
        $user = $request->user();
        $target = $user instanceof User
            ? $this->resolver->resolve($request, $user, consumeIntended: true)
            : route('login');

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return new JsonResponse(['two_factor' => false, 'redirect' => $target], 200);
        }

        return new RedirectResponse($target);
    }
}
