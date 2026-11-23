<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Enums\VoucherPaymentMode;
use App\Domains\Broker\Enums\VoucherPaymentType;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\LinkedBilty\LinkedBiltyGroupService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LinkedBiltyGroup;
use App\Models\LorryReceipt;
use App\Models\Party;
use App\Models\TransporterVoucher;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LinkedBiltyGroupController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly LinkedBiltyGroupService $groups,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LINKED_BILTY_VIEW);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        $page = $this->groups->search(
            company: $company,
            branch: $branch,
            status: $request->string('status')->toString() ?: null,
        );

        return Inertia::render('broker/linked-bilties/index', [
            'filters' => ['status' => $request->string('status')->toString()],
            'permissions' => $this->groupPermissions($actor, $company, $branch, $maySeeFreight),
            'groups' => [
                'data' => collect($page->items())->map(fn (LinkedBiltyGroup $row): array => $this->listPayload($row))->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'urls' => [
                'index' => route('branch.broker.linked-bilties.index', absolute: false),
                'create' => route('branch.broker.linked-bilties.create', absolute: false),
            ],
        ]);
    }

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LINKED_BILTY_CREATE);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $vehicleId = $request->string('vehicle_id')->toString() ?: null;

        $eligible = $this->groups->listEligibleLorryReceipts($company, $branch, $vehicleId);

        $vehicles = Vehicle::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('registration_number')
            ->limit(200)
            ->get(['id', 'registration_number']);

        $payees = Party::query()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereHas('roles', function ($query): void {
                $query->whereIn('role', [PartyRoleType::Transporter->value, PartyRoleType::Driver->value])
                    ->whereNull('deleted_at')
                    ->whereNull('deactivated_at');
            })
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name']);

        return Inertia::render('broker/linked-bilties/create', [
            'filters' => ['vehicle_id' => $vehicleId],
            'vehicles' => $vehicles->map(fn (Vehicle $row): array => [
                'id' => $row->id,
                'registration_number' => $row->registration_number,
            ])->values()->all(),
            'payees' => $payees->map(fn (Party $row): array => [
                'id' => $row->id,
                'name' => $row->name,
            ])->values()->all(),
            'eligibleReceipts' => $eligible->map(fn (LorryReceipt $lr): array => $this->eligibleReceiptPayload($lr, $maySeeFreight))->values()->all(),
            'permissions' => $this->groupPermissions($actor, $company, $branch, $maySeeFreight),
            'urls' => [
                'index' => route('branch.broker.linked-bilties.index', absolute: false),
                'store' => route('branch.broker.linked-bilties.store', absolute: false),
                'filter' => route('branch.broker.linked-bilties.create', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'group_date' => ['nullable', 'date'],
            'vehicle_id' => ['required', 'uuid'],
            'payee_party_id' => ['nullable', 'uuid'],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'reference' => ['nullable', 'string', 'max:128'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lorry_receipt_ids' => ['required', 'array', 'min:2'],
            'lorry_receipt_ids.*' => ['required', 'uuid'],
        ]);

        try {
            $group = $this->groups->createGroup($actor, $company, $branch, $validated);
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException|ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('branch.broker.linked-bilties.show', [$group])
            ->with('success', 'Linked Bilty group '.$group->group_number.' created.');
    }

    public function show(Request $request, LinkedBiltyGroup $linkedBiltyGroup, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertGroupTenant($linkedBiltyGroup, $company, $branch);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LINKED_BILTY_VIEW);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $group = $this->groups->findForBranch($company, $branch, $linkedBiltyGroup->id);
        $members = $this->groups->ensureLorryHires($group);

        return Inertia::render('broker/linked-bilties/show', [
            'group' => $this->detailPayload($group, $members, $maySeeFreight),
            'paymentTypeOptions' => $this->paymentTypeOptions(),
            'paymentModeOptions' => $this->paymentModeOptions(),
            'bankCashAccounts' => BankCashAccount::query()
                ->where('company_id', $company->id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'account_kind'])
                ->map(fn (BankCashAccount $row): array => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'account_kind' => $row->account_kind,
                ])->values()->all(),
            'permissions' => $this->groupPermissions($actor, $company, $branch, $maySeeFreight),
            'urls' => [
                'index' => route('branch.broker.linked-bilties.index', absolute: false),
                'pay_preview' => route('branch.broker.linked-bilties.pay.preview', [$group], absolute: false),
                'pay' => route('branch.broker.linked-bilties.pay.store', [$group], absolute: false),
            ],
        ]);
    }

    public function payPreview(Request $request, LinkedBiltyGroup $linkedBiltyGroup, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertGroupTenant($linkedBiltyGroup, $company, $branch);
        $this->assertPayPermission($actor, $company, $branch);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_type' => ['required', Rule::enum(VoucherPaymentType::class)],
            'payment_mode' => ['required', Rule::enum(VoucherPaymentMode::class)],
        ]);

        $group = $this->groups->findForBranch($company, $branch, $linkedBiltyGroup->id);
        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        try {
            $preview = $this->groups->previewGroupPayment(
                $group,
                $validated['amount'],
                $validated['payment_type'],
                $validated['payment_mode'],
            );
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $preview['lines'] = collect($preview['lines'])->map(function (array $line) use ($maySeeFreight): array {
            $line['eligible_outstanding'] = $this->freightPresentation->presentAmount($line['eligible_outstanding'], $maySeeFreight);
            $line['allocated_amount'] = $this->freightPresentation->presentAmount($line['allocated_amount'], $maySeeFreight);

            return $line;
        })->values()->all();
        $preview['payment_amount'] = $this->freightPresentation->presentAmount($preview['payment_amount'], $maySeeFreight);
        $preview['total_eligible'] = $this->freightPresentation->presentAmount($preview['total_eligible'], $maySeeFreight);

        return response()->json($preview);
    }

    public function payStore(Request $request, LinkedBiltyGroup $linkedBiltyGroup, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertGroupTenant($linkedBiltyGroup, $company, $branch);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_type' => ['required', Rule::enum(VoucherPaymentType::class)],
            'payment_mode' => ['required', Rule::enum(VoucherPaymentMode::class)],
            'voucher_date' => ['nullable', 'date'],
            'bank_cash_account_id' => ['nullable', 'uuid'],
            'cash_reference' => ['nullable', 'string', 'max:128'],
            'pump_reference' => ['nullable', 'string', 'max:128'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $group = $this->groups->findForBranch($company, $branch, $linkedBiltyGroup->id);

        try {
            $vouchers = $this->groups->payGroup($group, $actor, $validated);
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException|ValidationException $e) {
            throw $e;
        }

        /** @var TransporterVoucher|null $first */
        $first = $vouchers[0] ?? null;

        return redirect()
            ->route('branch.broker.linked-bilties.show', [$group])
            ->with('success', count($vouchers).' transporter vouchers created for group payment.');
    }

    private function assertGroupTenant(LinkedBiltyGroup $group, Company $company, Branch $branch): void
    {
        if ($group->company_id !== $company->id || $group->branch_id !== $branch->id || $group->trashed()) {
            abort(404);
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        $this->assertBrokerModule($company);

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Linked Bilty operation.');
        }
    }

    private function assertPayPermission(User $actor, Company $company, Branch $branch): void
    {
        $this->assertBrokerModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_LINKED_BILTY_PAY);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_CREATE);
    }

    private function assertBrokerModule(Company $company): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Broker)) {
            abort(403, 'Broker module is not entitled for this company.');
        }
    }

    /**
     * @return array<string, bool>
     */
    private function groupPermissions(User $actor, Company $company, Branch $branch, bool $maySeeFreight): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LINKED_BILTY_VIEW, $company, $branch),
            'can_create' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LINKED_BILTY_CREATE, $company, $branch),
            'can_pay' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_LINKED_BILTY_PAY, $company, $branch)
                && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_CREATE, $company, $branch),
            'can_view_freight' => $maySeeFreight,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(LinkedBiltyGroup $group): array
    {
        return [
            'id' => $group->id,
            'group_number' => $group->group_number,
            'group_date' => $group->group_date?->format('d-m-Y'),
            'status' => $group->status,
            'vehicle_registration' => $group->vehicle?->registration_number,
            'member_count' => $group->members_count ?? $group->members->count(),
            'urls' => [
                'show' => route('branch.broker.linked-bilties.show', [$group], absolute: false),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function detailPayload(LinkedBiltyGroup $group, array $members, bool $maySeeFreight): array
    {
        return [
            'id' => $group->id,
            'group_number' => $group->group_number,
            'group_date' => $group->group_date?->format('d-m-Y'),
            'status' => $group->status,
            'driver_name' => $group->driver_name,
            'reference' => $group->reference,
            'remarks' => $group->remarks,
            'vehicle_registration' => $group->vehicle?->registration_number,
            'payee_name' => $group->payee?->name,
            'members' => collect($members)->map(function (array $row) use ($maySeeFreight): array {
                return [
                    'member_id' => $row['member_id'],
                    'lorry_receipt_id' => $row['lorry_receipt_id'],
                    'lorry_hire_id' => $row['lorry_hire_id'],
                    'lr_number' => $row['lr_number'],
                    'hire_number' => $row['hire_number'],
                    'has_hire' => $row['has_hire'],
                    'allocatable_amount' => $this->freightPresentation->presentAmount($row['allocatable_amount'], $maySeeFreight),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eligibleReceiptPayload(LorryReceipt $lr, bool $maySeeFreight): array
    {
        return [
            'id' => $lr->id,
            'lr_number' => $lr->lr_number,
            'lr_date' => $lr->lr_date?->format('d-m-Y'),
            'vehicle_id' => $lr->vehicle_id,
            'vehicle_registration' => $lr->vehicle?->registration_number,
            'billing_customer_name' => $lr->billingCustomer?->name,
            'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function paymentTypeOptions(): array
    {
        return array_values(collect(VoucherPaymentType::cases())->map(fn (VoucherPaymentType $type): array => [
            'value' => $type->value,
            'label' => str_replace('_', ' ', ucfirst($type->value)),
        ])->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function paymentModeOptions(): array
    {
        return array_values(collect(VoucherPaymentMode::cases())->map(fn (VoucherPaymentMode $mode): array => [
            'value' => $mode->value,
            'label' => str_replace('_', ' ', ucfirst($mode->value)),
        ])->all());
    }
}
