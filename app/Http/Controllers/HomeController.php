<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Workspace\WorkspaceLandingController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Application gateway at `/`.
 * Authenticated users use the approved workspace landing resolver.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return app(WorkspaceLandingController::class)($request);
        }

        return Inertia::render('welcome', [
            'website_url' => rtrim((string) config('lead_onboarding.frontend_url', 'https://truckbill.in'), '/'),
            'demo_url' => rtrim((string) config('lead_onboarding.frontend_url', 'https://truckbill.in'), '/').'/#book-demo',
            'policies' => [
                'privacy' => (string) config('lead_onboarding.policies.privacy'),
                'terms' => (string) config('lead_onboarding.policies.terms'),
            ],
            'support_email' => 'support@truckbill.in',
        ]);
    }
}
