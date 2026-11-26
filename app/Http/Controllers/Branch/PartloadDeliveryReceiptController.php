<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Partload\Delivery\PartloadDeliveryReceiptService;
use App\Domains\Partload\Enums\PartloadDeliveryPaymentMode;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\BankCashAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartloadDeliveryReceiptController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly PartloadDeliveryReceiptService $deliveryReceipts,
        private readonly PartyOptionQuery $partyOptions = new PartyOptionQuery,
    ) {}

    public function create(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $lrId = $request->string('lorry_receipt_id')->toString();
        $lr = LorryReceipt::query()
            ->with(['consignee:id,name', 'billingCustomer:id,name'])
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereKey($lrId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $masterPermissions = $this->masterPermissions($actor, $company, $branch, $this->access, $this->authorizer);

        return Inertia::render('partload/delivery-receipts/create', [
            'lorryReceipt' => [
                'id' => $lr->id,
                'lr_number' => $lr->lr_number,
                'freight_total' => (string) $lr->freight_total,
                'consignee_name' => $lr->consignee?->name,
                'billing_customer_name' => $lr->billingCustomer?->name,
            ],
            'partload_inward_line_id' => $request->string('partload_inward_line_id')->toString() ?: null,
            'paymentModeOptions' => collect(PartloadDeliveryPaymentMode::cases())->map(fn (PartloadDeliveryPaymentMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
            ])->values()->all(),
            'bankCashAccounts' => BankCashAccount::query()
                ->where('company_id', $company->id)
                ->where(function ($query) use ($branch): void {
                    $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
                })
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (BankCashAccount $account): array => ['id' => $account->id, 'name' => $account->name])
                ->values()
                ->all(),
            'dueFromParties' => $this->partyOptions->forRoles(
                $company,
                $branch,
                [PartyRoleType::Customer, PartyRoleType::Consignee],
            ),
            'permissions' => [
                'can_create_bank_cash' => $masterPermissions['can_create'],
                'can_create_party' => $masterPermissions['can_create'],
            ],
            'urls' => [
                'store' => route('branch.partload.delivery-receipts.store', absolute: false),
                'bank_cash_store' => route('branch.bank-cash.store', absolute: false),
                'parties_options' => route('branch.parties.options', absolute: false),
                'parties_store' => route('branch.parties.store', absolute: false),
            ],
        ]);
    }

    public function store(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $validated = $request->validate([
            'lorry_receipt_id' => ['required', 'uuid'],
            'partload_inward_line_id' => ['nullable', 'uuid'],
            'dr_date' => ['nullable', 'date'],
            'payment_mode' => ['required', Rule::enum(PartloadDeliveryPaymentMode::class)],
            'payment_due_from_party_id' => ['nullable', 'uuid'],
            'bank_cash_account_id' => ['nullable', 'uuid'],
            'freight' => ['nullable', 'numeric', 'min:0'],
            'handling_charge' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'stationery_charge' => ['nullable', 'numeric', 'min:0'],
            'demurrage' => ['nullable', 'numeric', 'min:0'],
            'cartage_charge' => ['nullable', 'numeric', 'min:0'],
            'other_charge' => ['nullable', 'numeric', 'min:0'],
            'gst_amount' => ['nullable', 'numeric', 'min:0'],
            'less_amount' => ['nullable', 'numeric', 'min:0'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $dr = $this->deliveryReceipts->create($company, $branch, $actor, $validated);
        } catch (PartloadAuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (PartloadConflictException $e) {
            throw ValidationException::withMessages(['lorry_receipt_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.lorry-receipts.show', $dr->lorry_receipt_id)
            ->with('success', 'Delivery Receipt '.$dr->dr_number.' posted.');
    }
}
