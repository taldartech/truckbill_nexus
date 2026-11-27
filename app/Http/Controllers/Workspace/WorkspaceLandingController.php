<?php

namespace App\Http\Controllers\Workspace;

use App\Domains\Auth\PostLoginLandingResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Post-login / workspace entry resolver — delegates to PostLoginLandingResolver.
 */
class WorkspaceLandingController extends Controller
{
    public function __construct(
        private readonly PostLoginLandingResolver $resolver,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return redirect()->to(
            $this->resolver->resolve($request, $user, consumeIntended: false)
        );
    }
}
