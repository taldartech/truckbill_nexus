<?php

namespace App\Domains\CustomerInvoice;

use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\CustomerInvoice\Enums\CustomerInvoiceSource;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\BrokerInvoice;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\TripInvoice;
use App\Models\User;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Read-only consolidation of OwnFleet trip invoices and Broker customer invoices.
 */
final class UnifiedCustomerInvoiceReadFacade
{
    public function __construct(
        private readonly TripInvoiceService $tripInvoices,
        private readonly BrokerInvoiceService $brokerInvoices,
        private readonly PermissionAuthorizer $authorizer,
        private readonly FreightAmountPresentation $freightPresentation,
    ) {}

    public function canViewOwnFleet(User $actor, Company $company, Branch $branch): bool
    {
        return $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_VIEW, $company, $branch);
    }

    public function canViewBroker(User $actor, Company $company, Branch $branch): bool
    {
        return $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_VIEW, $company, $branch);
    }

    public function canCreateOwnFleet(User $actor, Company $company, Branch $branch): bool
    {
        return $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch);
    }

    public function canCreateBroker(User $actor, Company $company, Branch $branch): bool
    {
        return $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_CREATE, $company, $branch);
    }

    /**
     * @param  array{
     *     q?: string|null,
     *     customer_id?: string|null,
     *     source?: string|null,
     *     payment_status?: string|null,
     *     invoice_status?: string|null,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function search(
        User $actor,
        Company $company,
        Branch $branch,
        array $filters,
        bool $maySeeFreight,
    ): LengthAwarePaginator {
        $canTrip = $this->canViewOwnFleet($actor, $company, $branch);
        $canBroker = $this->canViewBroker($actor, $company, $branch);

        if (! $canTrip && ! $canBroker) {
            abort(403, 'Not authorized to view customer invoices.');
        }

        $sourceFilter = filled($filters['source'] ?? null)
            ? CustomerInvoiceSource::tryFrom((string) $filters['source'])
            : null;

        if ($sourceFilter === CustomerInvoiceSource::OwnFleet && ! $canTrip) {
            throw ValidationException::withMessages(['source' => 'Not authorized for Own Fleet invoices.']);
        }

        if ($sourceFilter === CustomerInvoiceSource::Broker && ! $canBroker) {
            throw ValidationException::withMessages(['source' => 'Not authorized for Broker invoices.']);
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $rows = new Collection;

        if ($canTrip && ($sourceFilter === null || $sourceFilter === CustomerInvoiceSource::OwnFleet)) {
            $rows = $rows->merge(
                $this->tripInvoiceRows($company, $branch, $filters, $maySeeFreight),
            );
        }

        if ($canBroker && ($sourceFilter === null || $sourceFilter === CustomerInvoiceSource::Broker)) {
            $rows = $rows->merge(
                $this->brokerInvoiceRows($company, $branch, $filters, $maySeeFreight),
            );
        }

        $sorted = $rows
            ->sortByDesc(fn (array $row): string => ($row['sort_key'] ?? '').'|'.$row['id'])
            ->values();

        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values()->map(function (array $row): array {
            unset($row['sort_key']);

            return $row;
        });

        return new Paginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  list<string>  $lorryReceiptIds
     */
    public function resolveSingleSource(Company $company, Branch $branch, array $lorryReceiptIds): CustomerInvoiceSource
    {
        if ($lorryReceiptIds === []) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Select at least one lorry receipt.',
            ]);
        }

        $receipts = LorryReceipt::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereIn('id', $lorryReceiptIds)
            ->whereNull('deleted_at')
            ->get(['id', 'workflow_context']);

        if ($receipts->count() !== count($lorryReceiptIds)) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'One or more lorry receipts are invalid for this branch.',
            ]);
        }

        $sources = $receipts
            ->map(function (LorryReceipt $receipt): CustomerInvoiceSource {
                return match ($receipt->workflow_context) {
                    LrWorkflowContext::OwnFleet => CustomerInvoiceSource::OwnFleet,
                    LrWorkflowContext::Broker => CustomerInvoiceSource::Broker,
                    default => throw ValidationException::withMessages([
                        'lorry_receipt_ids' => 'Only Own Fleet and Broker lorry receipts can be invoiced here.',
                    ]),
                };
            })
            ->unique(fn (CustomerInvoiceSource $source): string => $source->value);

        if ($sources->count() > 1) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Cannot combine Own Fleet and Broker lorry receipts on one customer invoice.',
            ]);
        }

        return $sources->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listEligibleLorryReceipts(
        User $actor,
        Company $company,
        Branch $branch,
        bool $maySeeFreight,
    ): Collection {
        $rows = new Collection;

        if ($this->canCreateOwnFleet($actor, $company, $branch)) {
            $this->tripInvoices->listInvoiceEligibleLorryReceipts($company, $branch)
                ->each(function (LorryReceipt $lr) use ($rows, $maySeeFreight): void {
                    $rows->push([
                        'id' => $lr->id,
                        'lr_number' => $lr->lr_number,
                        'lr_date' => $lr->lr_date?->format('d-m-Y'),
                        'billing_customer_name' => $lr->billingCustomer?->name,
                        'billing_customer_party_id' => $lr->billing_customer_party_id,
                        'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
                        'source' => CustomerInvoiceSource::OwnFleet->value,
                        'source_label' => CustomerInvoiceSource::OwnFleet->label(),
                    ]);
                });
        }

        if ($this->canCreateBroker($actor, $company, $branch)) {
            $eligible = $this->brokerInvoices->listEligibleLorryReceipts($company, $branch);
            foreach ([$eligible['with_memo'], $eligible['without_memo']] as $bucket) {
                $bucket->each(function (LorryReceipt $lr) use ($rows, $maySeeFreight): void {
                    $rows->push([
                        'id' => $lr->id,
                        'lr_number' => $lr->lr_number,
                        'lr_date' => $lr->lr_date?->format('d-m-Y'),
                        'billing_customer_name' => $lr->billingCustomer?->name,
                        'billing_customer_party_id' => $lr->billing_customer_party_id,
                        'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
                        'source' => CustomerInvoiceSource::Broker->value,
                        'source_label' => CustomerInvoiceSource::Broker->label(),
                    ]);
                });
            }
        }

        return $rows->sortBy('lr_number')->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function tripInvoiceRows(Company $company, Branch $branch, array $filters, bool $maySeeFreight): Collection
    {
        $builder = TripInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['billingCustomer', 'lines.lorryReceipt']);

        if (filled($filters['q'] ?? null)) {
            $builder->where('invoice_number', 'ilike', '%'.((string) $filters['q']).'%');
        }

        if (filled($filters['customer_id'] ?? null)) {
            $builder->where('billing_customer_party_id', (string) $filters['customer_id']);
        }

        if (filled($filters['payment_status'] ?? null)) {
            $builder->where('payment_status', (string) $filters['payment_status']);
        }

        if (filled($filters['invoice_status'] ?? null)) {
            $builder->where('status', (string) $filters['invoice_status']);
        }

        return $builder
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (TripInvoice $invoice): array => $this->mapTripRow($invoice, $maySeeFreight));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function brokerInvoiceRows(Company $company, Branch $branch, array $filters, bool $maySeeFreight): Collection
    {
        $builder = BrokerInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['billingCustomer', 'lines.lorryReceipt']);

        if (filled($filters['q'] ?? null)) {
            $builder->where('invoice_number', 'ilike', '%'.((string) $filters['q']).'%');
        }

        if (filled($filters['customer_id'] ?? null)) {
            $builder->where('billing_customer_party_id', (string) $filters['customer_id']);
        }

        if (filled($filters['payment_status'] ?? null)) {
            $builder->where('payment_status', (string) $filters['payment_status']);
        }

        if (filled($filters['invoice_status'] ?? null)) {
            $builder->where('status', (string) $filters['invoice_status']);
        }

        return $builder
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (BrokerInvoice $invoice): array => $this->mapBrokerRow($invoice, $maySeeFreight));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTripRow(TripInvoice $invoice, bool $maySeeFreight): array
    {
        $lrNumbers = $invoice->lines
            ->map(fn ($line) => $line->lorryReceipt?->lr_number)
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $invoice->id,
            'source' => CustomerInvoiceSource::OwnFleet->value,
            'source_label' => CustomerInvoiceSource::OwnFleet->label(),
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->format('d-m-Y'),
            'billing_customer_name' => $invoice->billingCustomer?->name,
            'billing_customer_party_id' => $invoice->billing_customer_party_id,
            'lr_numbers' => $lrNumbers,
            'invoice_status' => $invoice->status->value,
            'payment_status' => $invoice->payment_status->value,
            'invoice_total' => $this->freightPresentation->presentAmount($invoice->invoice_total, $maySeeFreight),
            'balance_due' => $this->freightPresentation->presentAmount($invoice->balance_due, $maySeeFreight),
            'urls' => [
                'show' => route('branch.trip-invoices.show', [$invoice], absolute: false),
            ],
            'sort_key' => $invoice->invoice_date->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBrokerRow(BrokerInvoice $invoice, bool $maySeeFreight): array
    {
        $lrNumbers = $invoice->lines
            ->map(fn ($line) => $line->lorryReceipt?->lr_number)
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $invoice->id,
            'source' => CustomerInvoiceSource::Broker->value,
            'source_label' => CustomerInvoiceSource::Broker->label(),
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('d-m-Y'),
            'billing_customer_name' => $invoice->billingCustomer?->name,
            'billing_customer_party_id' => $invoice->billing_customer_party_id,
            'lr_numbers' => $lrNumbers,
            'invoice_status' => $invoice->status->value,
            'payment_status' => $invoice->payment_status->value,
            'invoice_total' => $this->freightPresentation->presentAmount($invoice->invoice_total, $maySeeFreight),
            'balance_due' => $this->freightPresentation->presentAmount($invoice->balance_due, $maySeeFreight),
            'urls' => [
                'show' => route('branch.broker.invoices.show', [$invoice], absolute: false),
            ],
            'sort_key' => $invoice->invoice_date?->format('Y-m-d') ?? '',
        ];
    }
}
