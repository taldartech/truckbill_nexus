<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Adjustment\BrokerAdjustmentService;
use App\Domains\Broker\Enums\BrokerAdjustmentType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LorryHire\LorryHireCalculationService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BrokerAdjustment;
use App\Models\Company;
use App\Models\LorryHire;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BrokerAdjustmentController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly BrokerAdjustmentService $adjustments,
        private readonly LorryHireCalculationService $calculations,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, LorryHire $lorryHire, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_ADJUSTMENT_VIEW);
        $this->assertLorryHire($lorryHire, $company, $branch);

        $rows = $this->adjustments->listForHire($company, $branch, $lorryHire);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $summary = $this->calculations->summarize($lorryHire->fresh() ?? $lorryHire);

        return response()->json([
            'adjustments' => $rows->map(fn (BrokerAdjustment $row): array => $this->adjustmentPayload($row, $maySeeFreight))->values()->all(),
            'summary' => [
                'freight_total' => $this->freightPresentation->presentAmount($summary['freight_total'], $maySeeFreight),
                'tds_amount' => $this->freightPresentation->presentAmount($summary['tds_amount'], $maySeeFreight),
                'net_payable' => $this->freightPresentation->presentAmount($summary['net_payable'], $maySeeFreight),
                'outstanding_amount' => $this->freightPresentation->presentAmount($summary['outstanding_amount'], $maySeeFreight),
            ],
            'typeOptions' => collect(BrokerAdjustmentType::cases())->map(fn (BrokerAdjustmentType $type): array => [
                'value' => $type->value,
                'label' => ucwords(str_replace('_', ' ', $type->value)),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, LorryHire $lorryHire, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_ADJUSTMENT_CREATE);
        $this->assertLorryHire($lorryHire, $company, $branch);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(BrokerAdjustmentType::class)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'new_weight' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        try {
            $this->adjustments->store($lorryHire, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['type' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.lorry-hires.show', [$lorryHire])
            ->with('success', 'Adjustment recorded.');
    }

    private function assertLorryHire(LorryHire $lorryHire, Company $company, Branch $branch): void
    {
        if ($lorryHire->company_id !== $company->id || $lorryHire->branch_id !== $branch->id || $lorryHire->trashed()) {
            abort(404);
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        $this->assertBrokerModule($company);

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker adjustment operation.');
        }
    }

    private function assertBrokerModule(Company $company): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Broker)) {
            abort(403, 'Broker module is not entitled for this company.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function adjustmentPayload(BrokerAdjustment $row, bool $maySeeFreight): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type->value,
            'amount' => $this->freightPresentation->presentAmount($row->amount, $maySeeFreight),
            'previous_weight' => $row->previous_weight,
            'new_weight' => $row->new_weight,
            'quantity' => $row->quantity,
            'rate' => $row->rate,
            'reason' => $row->reason,
            'occurred_at' => $row->occurred_at?->format('d-m-Y H:i'),
            'created_at' => $row->created_at?->format('d-m-Y H:i'),
        ];
    }
}
