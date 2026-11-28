<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Enums\VoucherStatus;
use App\Domains\Broker\Exceptions\BrokerAuthorizationException;
use App\Domains\Broker\Exceptions\BrokerConflictException;
use App\Domains\Broker\Exceptions\BrokerDomainException;
use App\Domains\Broker\Presentation\TransporterVoucherRegisterPresenter;
use App\Domains\Broker\Voucher\TransporterVoucherService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\TransporterVoucher;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BrokerDailyPaymentsController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TransporterVoucherService $vouchers,
        private readonly FreightAmountPresentation $freightPresentation,
        private readonly TransporterVoucherRegisterPresenter $registerRows,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::BROKER_VOUCHER_VIEW);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $forDate = $request->string('date')->toString();
        if ($forDate === '') {
            $forDate = CarbonImmutable::now($this->companyTimezone($company))->toDateString();
        }

        $page = $this->vouchers->search(
            company: $company,
            branch: $branch,
            status: $request->string('status')->toString() ?: null,
            voucherDate: $forDate,
            perPage: min(200, max(1, (int) $request->input('per_page', 50))),
        );

        $rows = collect($page->items());
        $amountTotal = $rows->reduce(
            fn (float $carry, TransporterVoucher $row): float => $carry + (float) $row->amount,
            0.0,
        );

        return Inertia::render('broker/daily-payments/index', [
            'filters' => [
                'date' => $forDate,
                'status' => $request->string('status')->toString(),
            ],
            'totals' => [
                'count' => $rows->count(),
                'amount' => $maySeeFreight
                    ? number_format($amountTotal, 2, '.', '')
                    : null,
            ],
            'permissions' => [
                'can_status' => $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_VOUCHER_STATUS, $company, $branch),
                'can_view_freight' => $maySeeFreight,
            ],
            'statusOptions' => collect(VoucherStatus::cases())->map(fn (VoucherStatus $status): array => [
                'value' => $status->value,
                'label' => ucwords(str_replace('_', ' ', $status->value)),
            ])->values()->all(),
            'vouchers' => $rows->map(
                fn (TransporterVoucher $row): array => $this->dailyPaymentVoucherRow($row, $maySeeFreight),
            )->values()->all(),
            'urls' => [
                'index' => route('branch.broker.daily-payments.index', absolute: false),
                'bulk_status' => route('branch.broker.daily-payments.bulk-status', absolute: false),
            ],
        ]);
    }

    public function bulkStatus(Request $request, Company $company, Branch $branch): RedirectResponse
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);

        $validated = $request->validate([
            'voucher_ids' => ['required', 'array', 'min:1'],
            'voucher_ids.*' => ['uuid'],
            'status' => ['required', Rule::enum(VoucherStatus::class)],
        ]);

        try {
            $this->vouchers->bulkTransitionStatus(
                $company,
                $branch,
                $actor,
                $validated['voucher_ids'],
                VoucherStatus::from($validated['status']),
            );
        } catch (BrokerAuthorizationException|BrokerConflictException|BrokerDomainException $e) {
            throw ValidationException::withMessages(['voucher_ids' => $e->getMessage()]);
        }

        return redirect()
            ->route('branch.broker.daily-payments.index', ['date' => $request->input('date')])
            ->with('success', 'Voucher statuses updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyPaymentVoucherRow(TransporterVoucher $row, bool $maySeeFreight): array
    {
        return array_merge($this->registerRows->row($row, $maySeeFreight), [
            'next_status' => match ($row->status) {
                VoucherStatus::Pending => VoucherStatus::InProgress->value,
                VoucherStatus::InProgress => VoucherStatus::Paid->value,
                VoucherStatus::Paid => null,
            },
        ]);
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            abort(403, 'Not authorized for Broker daily payments.');
        }
    }

    private function companyTimezone(Company $company): string
    {
        $timezone = $company->timezone ?? null;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Asia/Kolkata';
    }
}
