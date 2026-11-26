<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Broker\Voucher\TransporterBookBalanceService;
use App\Domains\Company\CompanyAccessService;
use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\PartyOptionQuery;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Http\Controllers\Branch\Concerns\AuthorizesBranchPanel;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\TransporterBookBalanceEntry;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransporterBookBalanceController extends Controller
{
    use AuthorizesBranchPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly PermissionAuthorizer $authorizer,
        private readonly TransporterBookBalanceService $balances,
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    public function index(Request $request, Company $company, Branch $branch): Response
    {
        $actor = $this->activateBranchPanel($request, $company, $branch, $this->access);
        $this->assertPermission($actor, $company, $branch);

        $maySeeFreight = $this->freightPresentation->viewerMaySeeFreight($actor, $company, $branch);
        $transporterId = $request->string('transporter_party_id')->toString() ?: null;

        $transporters = app(PartyOptionQuery::class)
            ->forRoles($company, $branch, PartyRoleType::Transporter);

        $page = $this->balances->search(
            company: $company,
            branch: $branch,
            transporterPartyId: $transporterId,
            perPage: min(100, max(1, (int) $request->input('per_page', 25))),
        );

        $runningBalance = $transporterId !== null
            ? $this->balances->runningBalanceForTransporter($company, $branch, $transporterId)
            : null;

        return Inertia::render('broker/transporter-balances/index', [
            'filters' => [
                'transporter_party_id' => $transporterId ?? '',
            ],
            'transporters' => $transporters,
            'running_balance' => $runningBalance !== null
                ? $this->freightPresentation->presentAmount($runningBalance, $maySeeFreight)
                : null,
            'entries' => [
                'data' => collect($page->items())->map(
                    fn (TransporterBookBalanceEntry $entry): array => $this->bookBalanceEntryRow($entry, $maySeeFreight),
                )->values()->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                ],
            ],
            'permissions' => [
                'can_view_freight' => $maySeeFreight,
            ],
            'urls' => [
                'parties_options' => route('branch.parties.options', absolute: false),
                'index' => route('branch.broker.transporter-balances.index', absolute: false),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookBalanceEntryRow(TransporterBookBalanceEntry $entry, bool $maySeeFreight): array
    {
        return [
            'id' => $entry->id,
            'entry_type' => $entry->entry_type->value,
            'amount' => $this->freightPresentation->presentAmount($entry->amount, $maySeeFreight),
            'hire_number' => $entry->lorryHire?->hire_number,
            'remark' => $entry->remark,
            'created_at' => $entry->created_at?->format('d-m-Y H:i'),
        ];
    }

    private function assertPermission(User $actor, Company $company, Branch $branch): void
    {
        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_BOOK_BALANCE_VIEW, $company, $branch)) {
            abort(403, 'Not authorized to view transporter book balance.');
        }
    }
}
