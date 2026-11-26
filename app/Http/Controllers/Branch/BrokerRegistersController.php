<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Enums\LorryHireStatus;
use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Invoice\Enums\BrokerInvoicePaymentStatus;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BrokerInvoice;
use App\Models\Company;
use App\Models\LinkedBiltyGroup;
use App\Models\LorryHire;
use App\Models\LorryReceipt;
use App\Models\TransporterVoucher;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 6 Batch H — read-focused operational registers hub.
 */
class BrokerRegistersController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertAnyBrokerView($actor, $company, $branch);

        $counts = [
            'pending_lorry_hires' => $this->countWhenAllowed(
                $actor,
                $company,
                $branch,
                PermissionCodes::BROKER_LORRY_HIRE_VIEW,
                fn (): int => (int) LorryHire::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->where('status', LorryHireStatus::Pending->value)
                    ->whereNull('deleted_at')
                    ->count(),
            ),
            'pending_vouchers' => $this->countWhenAllowed(
                $actor,
                $company,
                $branch,
                PermissionCodes::BROKER_VOUCHER_VIEW,
                fn (): int => (int) TransporterVoucher::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->where('status', VoucherStatus::Pending->value)
                    ->whereNull('deleted_at')
                    ->count(),
            ),
            'memo_pod_queue' => $this->countWhenAllowed(
                $actor,
                $company,
                $branch,
                PermissionCodes::BROKER_DELIVERY_VIEW,
                fn (): int => (int) LorryReceipt::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->where('workflow_context', LrWorkflowContext::Broker->value)
                    ->where('status', '!=', LrStatus::Delivered->value)
                    ->whereNull('deleted_at')
                    ->whereHas('lorryHire', fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where('status', '!=', LorryHireStatus::Cancelled->value))
                    ->count(),
            ),
            'pending_customer_invoices' => $this->countWhenAllowed(
                $actor,
                $company,
                $branch,
                PermissionCodes::BROKER_INVOICE_VIEW,
                fn (): int => (int) BrokerInvoice::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->whereIn('payment_status', [
                        BrokerInvoicePaymentStatus::Unpaid->value,
                        BrokerInvoicePaymentStatus::Partial->value,
                    ])
                    ->whereNull('deleted_at')
                    ->count(),
            ),
            'linked_bilty_groups' => $this->countWhenAllowed(
                $actor,
                $company,
                $branch,
                PermissionCodes::BROKER_LINKED_BILTY_VIEW,
                fn (): int => (int) LinkedBiltyGroup::query()
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->whereNull('deleted_at')
                    ->count(),
            ),
        ];

        return Inertia::render('broker/registers/index', [
            'counts' => $counts,
            'registers' => $this->registerLinks($counts),
            'urls' => [
                'index' => route('branch.broker.registers.index', absolute: false),
            ],
        ]);
    }

    /**
     * @param  array<string, int|null>  $counts
     * @return list<array{key: string, title: string, description: string, href: string, count: int|null}>
     */
    private function registerLinks(array $counts): array
    {
        return [
            [
                'key' => 'lorry_hires',
                'title' => 'Lorry Hire register',
                'description' => 'All hires; filter status=pending for pending register.',
                'href' => route('branch.broker.lorry-hires.index', absolute: false),
                'count' => $counts['pending_lorry_hires'],
            ],
            [
                'key' => 'vouchers_pending',
                'title' => 'Pending transporter vouchers',
                'description' => 'Bank and pump vouchers awaiting processing.',
                'href' => route('branch.broker.vouchers.index', ['status' => VoucherStatus::Pending->value], absolute: false),
                'count' => $counts['pending_vouchers'],
            ],
            [
                'key' => 'vouchers_all',
                'title' => 'Voucher register',
                'description' => 'Full voucher list with status and payment mode filters.',
                'href' => route('branch.broker.vouchers.index', absolute: false),
                'count' => null,
            ],
            [
                'key' => 'memo_pod',
                'title' => 'Memo / POD register',
                'description' => 'Eligible Broker LRs and recent delivery memo assignments.',
                'href' => route('branch.broker.deliveries.index', absolute: false),
                'count' => $counts['memo_pod_queue'],
            ],
            [
                'key' => 'customer_invoices',
                'title' => 'Pending customer invoices',
                'description' => 'Unpaid and partial Broker billing invoices.',
                'href' => route('branch.broker.invoices.index', ['payment_status' => BrokerInvoicePaymentStatus::Unpaid->value], absolute: false),
                'count' => $counts['pending_customer_invoices'],
            ],
            [
                'key' => 'daily_payments',
                'title' => 'Daily payments',
                'description' => 'Voucher status board for a selected payment date.',
                'href' => route('branch.broker.daily-payments.index', absolute: false),
                'count' => null,
            ],
            [
                'key' => 'transporter_balances',
                'title' => 'Outstanding transporter balances',
                'description' => 'Book balance entries and running balance per transporter.',
                'href' => route('branch.broker.transporter-balances.index', absolute: false),
                'count' => $counts['pending_vouchers'],
            ],
            [
                'key' => 'linked_bilty',
                'title' => 'Linked bilty groups',
                'description' => 'Multi-LR groups and proportional group payments.',
                'href' => route('branch.broker.linked-bilties.index', absolute: false),
                'count' => $counts['linked_bilty_groups'],
            ],
        ];
    }

    private function assertAnyBrokerView(User $actor, Company $company, Branch $branch): void
    {
        $codes = [
            PermissionCodes::BROKER_LORRY_HIRE_VIEW,
            PermissionCodes::BROKER_VOUCHER_VIEW,
            PermissionCodes::BROKER_DELIVERY_VIEW,
            PermissionCodes::BROKER_INVOICE_VIEW,
            PermissionCodes::BROKER_BOOK_BALANCE_VIEW,
            PermissionCodes::BROKER_LINKED_BILTY_VIEW,
        ];

        foreach ($codes as $code) {
            if ($this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
                return;
            }
        }

        abort(403, 'Not authorized to view Broker operational registers.');
    }

    /**
     * @param  callable(): int  $counter
     */
    private function countWhenAllowed(
        User $actor,
        Company $company,
        Branch $branch,
        string $permission,
        callable $counter,
    ): ?int {
        if (! $this->authorizer->allowsCustomer($actor, $permission, $company, $branch)) {
            return null;
        }

        return $counter();
    }
}
