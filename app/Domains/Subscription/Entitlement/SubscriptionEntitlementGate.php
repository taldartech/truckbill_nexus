<?php

namespace App\Domains\Subscription\Entitlement;

use App\Domains\Subscription\Enums\SubscriptionLifecycleStatus;
use App\Domains\Subscription\Lifecycle\SubscriptionStatusEvaluator;
use App\Domains\Subscription\Support\SubscriptionClock;
use App\Models\Company;
use App\Models\CompanySubscriptionTrial;
use Carbon\CarbonImmutable;

/**
 * Enforces Confirmed Rules §15 subscription access only.
 *
 * Pending AUD-CON-006 cells are not invented: this gate applies confirmed
 * view-only paid grace, restricted/expired Branch mutation blocks, and preserves
 * Owner subscription/billing recovery access.
 */
final class SubscriptionEntitlementGate
{
    private readonly SubscriptionStatusEvaluator $evaluator;

    public function __construct(
        private readonly SubscriptionClock $clock = new SubscriptionClock,
        ?SubscriptionStatusEvaluator $evaluator = null,
    ) {
        $this->evaluator = $evaluator ?? new SubscriptionStatusEvaluator($this->clock);
    }

    /**
     * @return array{
     *     status: SubscriptionLifecycleStatus,
     *     company_admin_active: bool,
     *     branch_view_allowed: bool,
     *     branch_mutations_allowed: bool,
     *     owner_subscription_allowed: bool,
     *     owner_billing_allowed: bool,
     *     credit_consumption_allowed: bool,
     *     restriction_reason: string|null,
     *     matrix_authority: string,
     *     notes: list<string>
     * }
     */
    public function evaluate(Company $company): array
    {
        $effective = $this->evaluator->evaluate($company);
        $status = $effective['status'];
        $adminActive = $effective['company_admin_active'];
        $notes = $effective['notes'];
        $trialActive = $this->hasActiveTrial($company);

        // Company administrative deactivation: Branch Panel already blocked by Phase 2 / DEC-002.
        if (! $adminActive) {
            return [
                'status' => $status,
                'company_admin_active' => false,
                'branch_view_allowed' => false,
                'branch_mutations_allowed' => false,
                'owner_subscription_allowed' => true,
                'owner_billing_allowed' => true,
                'credit_consumption_allowed' => false,
                'restriction_reason' => 'Company is deactivated; Branch Panel blocked (DEC-002). Owner subscription/billing access remains.',
                'matrix_authority' => 'confirmed:DEC-002',
                'notes' => $notes,
            ];
        }

        return match ($status) {
            SubscriptionLifecycleStatus::Active,
            SubscriptionLifecycleStatus::Trial => [
                'status' => $status,
                'company_admin_active' => true,
                'branch_view_allowed' => true,
                'branch_mutations_allowed' => true,
                'owner_subscription_allowed' => true,
                'owner_billing_allowed' => true,
                'credit_consumption_allowed' => true,
                'restriction_reason' => null,
                'matrix_authority' => 'confirmed:Rules§15',
                'notes' => $notes,
            ],
            SubscriptionLifecycleStatus::PaidGrace => [
                'status' => $status,
                'company_admin_active' => true,
                'branch_view_allowed' => true,
                'branch_mutations_allowed' => false,
                'owner_subscription_allowed' => true,
                'owner_billing_allowed' => true,
                'credit_consumption_allowed' => false,
                'restriction_reason' => 'Paid grace is view-only (Rules §15). Branch mutations and credit consumption are blocked until renewal payment is verified.',
                'matrix_authority' => 'confirmed:Rules§15-paid-grace-view-only',
                'notes' => array_merge($notes, [
                    'Fine-grained create/edit/delete distinctions within grace remain Pending (AUD-CON-006); all Branch mutations blocked uniformly.',
                ]),
            ],
            SubscriptionLifecycleStatus::Restricted,
            SubscriptionLifecycleStatus::Cancelled,
            SubscriptionLifecycleStatus::None => [
                'status' => $status,
                'company_admin_active' => true,
                'branch_view_allowed' => true,
                'branch_mutations_allowed' => false,
                'owner_subscription_allowed' => true,
                'owner_billing_allowed' => true,
                'credit_consumption_allowed' => false,
                'restriction_reason' => 'Subscription is restricted/expired/cancelled. Branch mutations blocked; Owner Panel subscription and billing recovery remain available (Rules §15). Exact per-action matrix cells Pending (AUD-CON-006).',
                'matrix_authority' => 'confirmed:Rules§15-restricted-owner-remains',
                'notes' => $notes,
            ],
            SubscriptionLifecycleStatus::Draft,
            SubscriptionLifecycleStatus::PendingPayment => [
                'status' => $status,
                'company_admin_active' => true,
                'branch_view_allowed' => true,
                // Evaluator prefers draft/pending over trial; preserve first-company trial Branch access.
                'branch_mutations_allowed' => $trialActive,
                'owner_subscription_allowed' => true,
                'owner_billing_allowed' => true,
                'credit_consumption_allowed' => $trialActive,
                'restriction_reason' => $trialActive
                    ? null
                    : 'No paid or trial entitlement for Branch mutations. Complete verified payment or wait for trial coverage.',
                'matrix_authority' => 'confirmed:no-paid-activation-without-settlement',
                'notes' => $notes,
            ],
        };
    }

    public function allowsBranchMutation(Company $company): bool
    {
        return $this->evaluate($company)['branch_mutations_allowed'];
    }

    public function allowsCreditConsumption(Company $company): bool
    {
        return $this->evaluate($company)['credit_consumption_allowed'];
    }

    public function assertBranchMutationAllowed(Company $company): void
    {
        $decision = $this->evaluate($company);
        if (! $decision['branch_mutations_allowed']) {
            abort(403, $decision['restriction_reason'] ?? 'Branch mutations are not permitted for the current subscription lifecycle status.');
        }
    }

    private function hasActiveTrial(Company $company): bool
    {
        $trial = CompanySubscriptionTrial::query()->where('company_id', $company->id)->first();
        if ($trial === null) {
            return false;
        }

        $ends = CarbonImmutable::parse($trial->ends_at)->timezone(config('subscription.timezone', 'UTC'));

        return $this->clock->now()->lessThanOrEqualTo($ends);
    }
}
