<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Delivery\BrokerDeliveryService;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Document\Exceptions\DocumentDomainException;
use App\Domains\Master\Exceptions\MasterDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BrokerDelivery;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Presentation\FreightAmountPresentation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BrokerDeliveryController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly BrokerDeliveryService $deliveries,
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_DELIVERY_VIEW);

        return redirect()->route('branch.broker.lorry-hires.index', [
            'pending_memo' => 1,
        ]);
    }

    /**
     * Memo / POD modal facts for Bilties of one billing customer.
     */
    public function memoContext(Request $request, Company $company, Branch $branch): JsonResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_DELIVERY_MANAGE);

        $validated = $request->validate([
            'lorry_receipt_ids' => ['required', 'array', 'min:1', 'max:100'],
            'lorry_receipt_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        try {
            $receipts = $this->deliveries->memoCandidates($company, $branch, $validated['lorry_receipt_ids']);
        } catch (BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['lorry_receipt_ids' => $e->getMessage()]);
        }

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);

        return response()->json([
            'customer_name' => $receipts->first()?->billingCustomer?->name,
            'today' => CarbonImmutable::now($company->timezone ?: 'Asia/Kolkata')->toDateString(),
            'receipts' => $receipts->map(fn (LorryReceipt $lr): array => [
                'id' => $lr->id,
                'lr_number' => $lr->lr_number,
                'lr_date' => $lr->lr_date?->format('d-m-Y'),
                'vehicle_registration' => $lr->vehicle?->registration_number,
                'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
            ])->values()->all(),
        ]);
    }

    public function assignMemo(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_DELIVERY_MANAGE);

        $validated = $request->validate([
            'lorry_receipt_ids' => ['required', 'array', 'min:1', 'max:100'],
            'lorry_receipt_ids.*' => ['required', 'uuid', 'distinct'],
            'memo_number' => ['required', 'string', 'max:64'],
            'memo_date' => ['nullable', 'date'],
            'pod_document' => ['nullable', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], [
            'memo_number.required' => 'Enter the Memo / POD number.',
        ]);

        try {
            $deliveries = $this->deliveries->assignMemoPod($company, $branch, $actor, $validated);
        } catch (BrokerAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (BrokerConflictException|BrokerDomainException|DocumentDomainException|FileNotAvailableException $e) {
            throw ValidationException::withMessages(['lorry_receipt_ids' => $e->getMessage()]);
        }

        $count = $deliveries->count();

        return back()->with(
            'success',
            'Memo / POD '.trim($validated['memo_number']).' assigned to '.$count.' '.($count === 1 ? 'Bilty' : 'Bilties').'; marked delivered.',
        );
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
            ->route('branch.broker.lorry-hires.index')
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
            ->route('branch.broker.lorry-hires.index')
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
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker delivery operation.');
        }
    }
}
