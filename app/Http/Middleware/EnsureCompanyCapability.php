<?php

namespace App\Http\Middleware;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces Company subscription capability on Branch Panel routes.
 * Renders a branded upgrade/blocked experience for browser GET navigation
 * while returning HTTP 403 for all blocked requests (GET, JSON, API, mutations).
 */
final class EnsureCompanyCapability
{
    public function __construct(
        private readonly CompanyCapabilityResolver $capabilities,
        private readonly CompanyAccessService $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $capability, ?string $mode = null): Response
    {
        $company = $request->route('company');
        if (! $company instanceof Company) {
            abort(403, 'Branch Panel requires an active Company context.');
        }

        $requireMutation = $mode === 'mutate' || ! $request->isMethodSafe();
        $allowed = $requireMutation
            ? $this->capabilities->allowsMutate($company, $capability)
            : $this->capabilities->allowsRead($company, $capability);

        if ($allowed) {
            return $next($request);
        }

        $message = $this->capabilities->denyMessage($company, $capability);

        if ($request->isMethodSafe() && ! $request->expectsJson()) {
            /** @var User|null $actor */
            $actor = $request->user();
            $isOwner = $actor !== null && $this->access->isCompanyOwner($actor, $company);
            /** @var Branch|null $branch */
            $branch = $request->route('branch');
            $resolved = $this->capabilities->resolve($company);

            return Inertia::render('branch/capability-blocked', [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'status' => $company->isActive() ? 'active' : 'deactivated',
                ],
                'branch' => [
                    'id' => $branch instanceof Branch ? $branch->id : '',
                    'name' => $branch instanceof Branch ? $branch->name : 'Branch Workspace',
                    'status' => ($branch instanceof Branch && $branch->isActive()) ? 'active' : 'deactivated',
                ],
                'capability' => $capability,
                'message' => $message,
                'isReadOnlyLifecycle' => $resolved['is_read_only_grace_or_restricted'],
                'isOwner' => $isOwner,
                'manageSubscriptionUrl' => $isOwner
                    ? route('owner.subscriptions.index', absolute: false)
                    : null,
                'purchaseSubscriptionUrl' => $isOwner
                    ? route('owner.companies.subscription.purchase', ['company' => $company->id], absolute: false)
                    : null,
                'dashboardUrl' => route('branch.dashboard', absolute: false),
            ])->toResponse($request)->setStatusCode(403);
        }

        abort(403, $message);
    }
}
