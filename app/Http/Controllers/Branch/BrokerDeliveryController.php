<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\DocumentAttachmentService;
use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Branch\Concerns\PresentsDocumentDrawer;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BrokerDelivery;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Files\Exceptions\FileNotAvailableException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BrokerDeliveryController extends Controller
{
    use AuthorizesBranchPanel;
    use PresentsDocumentDrawer;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly BrokerDeliveryService $deliveries,
        private readonly DocumentAttachmentService $documents,
        private readonly CoreModuleEntitlementResolver $moduleEntitlements = new CoreModuleEntitlementResolver,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertBrokerModule($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_DELIVERY_VIEW);

        $eligible = $this->deliveries->searchEligible(
            company: $company,
            branch: $branch,
            billingCustomerId: $request->string('billing_customer_party_id')->toString() ?: null,
            perPage: min(100, max(1, (int) $request->input('per_page', 25))),
        );

        $recent = $this->deliveries->searchRecent($company, $branch, perPage: 10);

        return Inertia::render('broker/deliveries/index', [
            'filters' => [
                'billing_customer_party_id' => $request->string('billing_customer_party_id')->toString(),
            ],
            'permissions' => $this->deliveryPermissions($actor, $company, $branch),
            'eligible' => [
                'data' => collect($eligible->items())->map(fn (LorryReceipt $lr): array => $this->eligiblePayload($lr))->values()->all(),
                'meta' => [
                    'current_page' => $eligible->currentPage(),
                    'last_page' => $eligible->lastPage(),
                    'total' => $eligible->total(),
                    'per_page' => $eligible->perPage(),
                ],
            ],
            'recentDeliveries' => collect($recent->items())->map(
                fn (BrokerDelivery $delivery): array => $this->recentPayload($delivery, $actor, $company, $branch),
            )->values()->all(),
            'urls' => [
                'index' => route('branch.broker.deliveries.index', absolute: false),
                'memo' => route('branch.broker.deliveries.memo', absolute: false),
            ],
        ]);
    }

    public function assignMemo(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_DELIVERY_MANAGE);

        $validated = $request->validate([
            'lorry_receipt_ids' => ['required', 'array', 'min:1'],
            'lorry_receipt_ids.*' => ['required', 'uuid'],
            'memo_number' => ['required', 'string', 'max:64'],
            'pod_number' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->deliveries->assignMemoPod($company, $branch, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException $e) {
            throw ValidationException::withMessages(['memo_number' => $e->getMessage()]);
        } catch (BrokerDomainException $e) {
            throw ValidationException::withMessages(['lorry_receipt_ids' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.deliveries.index')
            ->with('success', 'Delivery memo assigned.');
    }

    public function complete(
        Request $request,
        LorryReceipt $lorryReceipt,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_DELIVERY_MANAGE);
        $this->assertLr($lorryReceipt, $company, $branch);

        $validated = $request->validate([
            'delivery_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->deliveries->recordDelivery($lorryReceipt, $actor, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['delivery_date' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.deliveries.index')
            ->with('success', 'Delivery recorded.');
    }

    public function attachPod(
        Request $request,
        BrokerDelivery $brokerDelivery,
        Company $company,
        Branch $branch,
    ): RedirectResponse {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_DELIVERY_MANAGE);
        $this->assertDelivery($brokerDelivery, $company, $branch);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png,webp'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->deliveries->attachPodDocument(
                $brokerDelivery,
                $actor,
                $validated['file'],
                $validated['label'] ?? null,
            );
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException|BrokerDomainException|DocumentDomainException|MasterDomainException|FileNotAvailableException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.deliveries.index')
            ->with('success', 'POD document uploaded.');
    }

    private function assertLr(LorryReceipt $lorryReceipt, Company $company, Branch $branch): void
    {
        if ($lorryReceipt->company_id !== $company->id || $lorryReceipt->branch_id !== $branch->id) {
            abort(404);
        }
    }

    private function assertDelivery(BrokerDelivery $delivery, Company $company, Branch $branch): void
    {
        if ($delivery->company_id !== $company->id || $delivery->branch_id !== $branch->id) {
            abort(404);
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        $this->assertBrokerModule($company);

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker delivery operation.');
        }
    }

    private function assertBrokerModule(Company $company): void
    {
        if (! $this->moduleEntitlements->isEntitled($company, SubscriptionModule::Broker)) {
            abort(403, 'Broker module is not entitled for this company.');
        }
    }

    /**
     * @return array{can_view: bool, can_manage: bool}
     */
    private function deliveryPermissions(User $actor, Company $company, Branch $branch): array
    {
        return [
            'can_view' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_DELIVERY_VIEW, $company, $branch),
            'can_manage' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_DELIVERY_MANAGE, $company, $branch),
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     lr_number: string,
     *     lr_date: string|null,
     *     billing_customer_party_id: string|null,
     *     billing_customer_name: string|null,
     *     delivery_status: string|null,
     *     memo_number: string|null,
     *     urls: array{complete: string}
     * }
     */
    private function eligiblePayload(LorryReceipt $lr): array
    {
        return [
            'id' => $lr->id,
            'lr_number' => $lr->lr_number,
            'lr_date' => $lr->lr_date?->format('d-m-Y'),
            'billing_customer_party_id' => $lr->billing_customer_party_id,
            'billing_customer_name' => $lr->billingCustomer?->name,
            'delivery_status' => $lr->delivery?->status?->value,
            'memo_number' => $lr->delivery?->memo_number,
            'urls' => [
                'complete' => route('branch.broker.deliveries.complete', [$lr], absolute: false),
            ],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     lr_number: string|null,
     *     status: string,
     *     memo_number: string|null,
     *     pod_number: string|null,
     *     delivery_date: string|null,
     *     urls: array{pod: string, documents: string},
     *     documentDrawer: array<string, mixed>|null
     * }
     */
    private function recentPayload(BrokerDelivery $delivery, User $actor, Company $company, Branch $branch): array
    {
        $canManage = $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_DELIVERY_MANAGE, $company, $branch);

        return [
            'id' => $delivery->id,
            'lr_number' => $delivery->lorryReceipt?->lr_number,
            'status' => $delivery->status->value,
            'memo_number' => $delivery->memo_number,
            'pod_number' => $delivery->pod_number,
            'delivery_date' => $delivery->delivery_date?->format('d-m-Y'),
            'urls' => [
                'pod' => route('branch.broker.deliveries.pod', [$delivery], absolute: false),
                'documents' => route('branch.documents.index', [
                    'parentType' => 'broker_delivery',
                    'parentId' => $delivery->id,
                ], absolute: false),
            ],
            'documentDrawer' => $this->documentDrawerPayload(
                $actor,
                $company,
                $branch,
                'broker_delivery',
                $delivery->id,
                $this->documents,
                [
                    'can_create' => $canManage,
                    'can_edit' => $canManage,
                    'can_delete' => $canManage,
                ],
            ),
        ];
    }
}
