<?php

namespace App\Domains\CustomerInvoice;

use App\Domains\Broker\Invoice\BrokerInvoiceService;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\CustomerInvoice\Enums\CustomerInvoiceSource;
use App\Domains\OwnFleet\Invoice\TripInvoiceService;
use App\Domains\Partload\Invoice\PartloadInvoiceService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CompanyCapabilities;
use App\Domains\Subscription\Entitlement\CompanyCapabilityResolver;
use App\Models\Branch;
use App\Models\BrokerInvoice;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\PartloadInvoice;
use App\Models\TripInvoice;
use App\Models\User;
use App\Support\Database\CaseInsensitiveLike;
use App\Support\Presentation\FreightAmountPresentation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Read-only consolidation of OwnFleet trip invoices and Broker customer invoices.
 */
final class UnifiedCustomerInvoiceReadFacade
{
    private readonly CompanyCapabilityResolver $capabilities;

    public function __construct(
        private readonly TripInvoiceService $tripInvoices,
        private readonly BrokerInvoiceService $brokerInvoices,
        private readonly PermissionAuthorizer $authorizer,
        private readonly FreightAmountPresentation $freightPresentation,
        ?CompanyCapabilityResolver $capabilities = null,
        private readonly ?PartloadInvoiceService $partloadInvoices = null,
    ) {
        $this->capabilities = $capabilities ?? app(CompanyCapabilityResolver::class);
    }

    private function partloadService(): PartloadInvoiceService
    {
        return $this->partloadInvoices ?? app(PartloadInvoiceService::class);
    }

    public function canViewOwnFleet(User $actor, Company $company, Branch $branch): bool
    {
        return $this->capabilities->allowsRead($company, CompanyCapabilities::OWNFLEET_INVOICES)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_VIEW, $company, $branch);
    }

    public function canViewBroker(User $actor, Company $company, Branch $branch): bool
    {
        return $this->capabilities->allowsRead($company, CompanyCapabilities::BROKER_INVOICES)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_VIEW, $company, $branch);
    }

    public function canViewPartload(User $actor, Company $company, Branch $branch): bool
    {
        return $this->capabilities->allowsRead($company, CompanyCapabilities::PARTLOAD_INVOICES)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_VIEW, $company, $branch);
    }

    public function canCreateOwnFleet(User $actor, Company $company, Branch $branch): bool
    {
        return $this->capabilities->allowsMutate($company, CompanyCapabilities::OWNFLEET_INVOICES)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch);
    }

    public function canCreateBroker(User $actor, Company $company, Branch $branch): bool
    {
        return $this->capabilities->allowsMutate($company, CompanyCapabilities::BROKER_INVOICES)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::BROKER_INVOICE_CREATE, $company, $branch);
    }

    public function canCreatePartload(User $actor, Company $company, Branch $branch): bool
    {
        return $this->capabilities->allowsMutate($company, CompanyCapabilities::PARTLOAD_INVOICES)
            && $this->authorizer->allowsCustomer($actor, PermissionCodes::INVOICE_CREATE, $company, $branch);
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
        $canPartload = $this->canViewPartload($actor, $company, $branch);

        if (! $canTrip && ! $canBroker && ! $canPartload) {
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

        if ($sourceFilter === CustomerInvoiceSource::Partload && ! $canPartload) {
            throw ValidationException::withMessages(['source' => 'Not authorized for Partload invoices.']);
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $includeTrip = $canTrip && ($sourceFilter === null || $sourceFilter === CustomerInvoiceSource::OwnFleet);
        $includeBroker = $canBroker && ($sourceFilter === null || $sourceFilter === CustomerInvoiceSource::Broker);
        $includePartload = $canPartload && ($sourceFilter === null || $sourceFilter === CustomerInvoiceSource::Partload);

        if ($includeTrip && ! $includeBroker && ! $includePartload) {
            return $this->paginateTripInvoices($company, $branch, $filters, $maySeeFreight, $perPage, $page);
        }

        if ($includeBroker && ! $includeTrip && ! $includePartload) {
            return $this->paginateBrokerInvoices($company, $branch, $filters, $maySeeFreight, $perPage, $page);
        }

        if ($includePartload && ! $includeTrip && ! $includeBroker) {
            return $this->paginatePartloadInvoices($company, $branch, $filters, $maySeeFreight, $perPage, $page);
        }

        return $this->paginateUnifiedUnion(
            $company,
            $branch,
            $filters,
            $maySeeFreight,
            $perPage,
            $page,
            $includeTrip,
            $includeBroker,
            $includePartload,
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
                    LrWorkflowContext::Partload => CustomerInvoiceSource::Partload,
                };
            })
            ->unique(fn (CustomerInvoiceSource $source): string => $source->value);

        if ($sources->count() > 1) {
            throw ValidationException::withMessages([
                'lorry_receipt_ids' => 'Cannot combine Own Fleet, Broker, and Partload lorry receipts on one customer invoice.',
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

        if ($this->canCreatePartload($actor, $company, $branch)) {
            $this->partloadService()->listEligibleLorryReceipts($company, $branch)
                ->each(function (LorryReceipt $lr) use ($rows, $maySeeFreight): void {
                    $dr = $lr->partloadDeliveryReceipt;
                    $customer = ($dr !== null ? $dr->dueFromParty : null) ?? $lr->billingCustomer;
                    $rows->push([
                        'id' => $lr->id,
                        'lr_number' => $lr->lr_number,
                        'lr_date' => $lr->lr_date?->format('d-m-Y'),
                        'billing_customer_name' => $customer?->name,
                        'billing_customer_party_id' => ($customer !== null ? $customer->id : null) ?? $lr->billing_customer_party_id,
                        'freight_total' => $this->freightPresentation->presentAmount($lr->freight_total, $maySeeFreight),
                        'source' => CustomerInvoiceSource::Partload->value,
                        'source_label' => CustomerInvoiceSource::Partload->label(),
                    ]);
                });
        }

        return $rows->sortBy('lr_number')->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateTripInvoices(
        Company $company,
        Branch $branch,
        array $filters,
        bool $maySeeFreight,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $builder = $this->tripInvoiceSearchQuery($company, $branch, $filters)
            ->with(['billingCustomer', 'lines.lorryReceipt'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        /** @var Paginator<int, TripInvoice> $paginator */
        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);

        return $paginator
            ->withPath(request()->url())
            ->appends(request()->query())
            ->through(fn (TripInvoice $invoice): array => $this->mapTripRow($invoice, $maySeeFreight));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateBrokerInvoices(
        Company $company,
        Branch $branch,
        array $filters,
        bool $maySeeFreight,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $builder = $this->brokerInvoiceSearchQuery($company, $branch, $filters)
            ->with(['billingCustomer', 'lines.lorryReceipt'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        /** @var Paginator<int, BrokerInvoice> $paginator */
        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);

        return $paginator
            ->withPath(request()->url())
            ->appends(request()->query())
            ->through(fn (BrokerInvoice $invoice): array => $this->mapBrokerRow($invoice, $maySeeFreight));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatePartloadInvoices(
        Company $company,
        Branch $branch,
        array $filters,
        bool $maySeeFreight,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $builder = $this->partloadInvoiceSearchQuery($company, $branch, $filters)
            ->with(['billingCustomer', 'lines.lorryReceipt'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        /** @var Paginator<int, PartloadInvoice> $paginator */
        $paginator = $builder->paginate($perPage, ['*'], 'page', $page);

        return $paginator
            ->withPath(request()->url())
            ->appends(request()->query())
            ->through(fn (PartloadInvoice $invoice): array => $this->mapPartloadRow($invoice, $maySeeFreight));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateUnifiedUnion(
        Company $company,
        Branch $branch,
        array $filters,
        bool $maySeeFreight,
        int $perPage,
        int $page,
        bool $includeTrip = true,
        bool $includeBroker = true,
        bool $includePartload = true,
    ): LengthAwarePaginator {
        $union = $this->unifiedUnionQuery($company, $branch, $filters, $includeTrip, $includeBroker, $includePartload);

        $total = (int) DB::query()->fromSub($union, 'unified_invoices')->count();

        /** @var Collection<int, stdClass> $orderedSlice */
        $orderedSlice = DB::query()
            ->fromSub($union, 'unified_invoices')
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $ownFleetValue = CustomerInvoiceSource::OwnFleet->value;
        $brokerValue = CustomerInvoiceSource::Broker->value;
        $partloadValue = CustomerInvoiceSource::Partload->value;

        $tripIds = $orderedSlice
            ->where('source', $ownFleetValue)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $brokerIds = $orderedSlice
            ->where('source', $brokerValue)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $partloadIds = $orderedSlice
            ->where('source', $partloadValue)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $trips = $tripIds === []
            ? collect()
            : TripInvoice::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereIn('id', $tripIds)
                ->with(['billingCustomer', 'lines.lorryReceipt'])
                ->get()
                ->keyBy('id');

        $brokers = $brokerIds === []
            ? collect()
            : BrokerInvoice::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereIn('id', $brokerIds)
                ->with(['billingCustomer', 'lines.lorryReceipt'])
                ->get()
                ->keyBy('id');

        $partloads = $partloadIds === []
            ? collect()
            : PartloadInvoice::query()
                ->where('company_id', $company->id)
                ->where('branch_id', $branch->id)
                ->whereIn('id', $partloadIds)
                ->with(['billingCustomer', 'lines.lorryReceipt'])
                ->get()
                ->keyBy('id');

        $items = $orderedSlice
            ->map(function (stdClass $row) use ($trips, $brokers, $partloads, $maySeeFreight, $ownFleetValue, $partloadValue): ?array {
                $id = (string) $row->id;

                if ($row->source === $ownFleetValue) {
                    $invoice = $trips->get($id);

                    return $invoice instanceof TripInvoice
                        ? $this->mapTripRow($invoice, $maySeeFreight)
                        : null;
                }

                if ($row->source === $partloadValue) {
                    $invoice = $partloads->get($id);

                    return $invoice instanceof PartloadInvoice
                        ? $this->mapPartloadRow($invoice, $maySeeFreight)
                        : null;
                }

                $invoice = $brokers->get($id);

                return $invoice instanceof BrokerInvoice
                    ? $this->mapBrokerRow($invoice, $maySeeFreight)
                    : null;
            })
            ->filter()
            ->values();

        /** @var Collection<int, array<string, mixed>> $items */
        return new Paginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function unifiedUnionQuery(
        Company $company,
        Branch $branch,
        array $filters,
        bool $includeTrip = true,
        bool $includeBroker = true,
        bool $includePartload = true,
    ): QueryBuilder {
        $queries = [];

        if ($includeTrip) {
            $ownFleetSource = CustomerInvoiceSource::OwnFleet->value;
            $queries[] = $this->tripInvoiceSearchQuery($company, $branch, $filters)
                ->select(['id', DB::raw("'{$ownFleetSource}' as source"), 'invoice_date', 'created_at'])
                ->toBase();
        }

        if ($includeBroker) {
            $brokerSource = CustomerInvoiceSource::Broker->value;
            $queries[] = $this->brokerInvoiceSearchQuery($company, $branch, $filters)
                ->select(['id', DB::raw("'{$brokerSource}' as source"), 'invoice_date', 'created_at'])
                ->toBase();
        }

        if ($includePartload) {
            $partloadSource = CustomerInvoiceSource::Partload->value;
            $queries[] = $this->partloadInvoiceSearchQuery($company, $branch, $filters)
                ->select(['id', DB::raw("'{$partloadSource}' as source"), 'invoice_date', 'created_at'])
                ->toBase();
        }

        $base = array_shift($queries);
        foreach ($queries as $q) {
            $base = $base->unionAll($q);
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<TripInvoice>
     */
    private function tripInvoiceSearchQuery(Company $company, Branch $branch, array $filters): Builder
    {
        $builder = TripInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at');

        $this->applyTripInvoiceFilters($builder, $filters);

        return $builder;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<BrokerInvoice>
     */
    private function brokerInvoiceSearchQuery(Company $company, Branch $branch, array $filters): Builder
    {
        $builder = BrokerInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at');

        $this->applyBrokerInvoiceFilters($builder, $filters);

        return $builder;
    }

    /**
     * @param  Builder<TripInvoice>  $builder
     * @param  array<string, mixed>  $filters
     */
    private function applyTripInvoiceFilters(Builder $builder, array $filters): void
    {
        if (filled($filters['q'] ?? null)) {
            CaseInsensitiveLike::whereContains($builder, 'invoice_number', (string) $filters['q']);
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
    }

    /**
     * @param  Builder<BrokerInvoice>  $builder
     * @param  array<string, mixed>  $filters
     */
    private function applyBrokerInvoiceFilters(Builder $builder, array $filters): void
    {
        if (filled($filters['q'] ?? null)) {
            CaseInsensitiveLike::whereContains($builder, 'invoice_number', (string) $filters['q']);
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
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<PartloadInvoice>
     */
    private function partloadInvoiceSearchQuery(Company $company, Branch $branch, array $filters): Builder
    {
        $builder = PartloadInvoice::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at');

        $this->applyPartloadInvoiceFilters($builder, $filters);

        return $builder;
    }

    /**
     * @param  Builder<PartloadInvoice>  $builder
     * @param  array<string, mixed>  $filters
     */
    private function applyPartloadInvoiceFilters(Builder $builder, array $filters): void
    {
        if (filled($filters['q'] ?? null)) {
            CaseInsensitiveLike::whereContains($builder, 'invoice_number', (string) $filters['q']);
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPartloadRow(PartloadInvoice $invoice, bool $maySeeFreight): array
    {
        $lrNumbers = $invoice->lines
            ->map(fn ($line) => $line->lorryReceipt?->lr_number)
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $invoice->id,
            'source' => CustomerInvoiceSource::Partload->value,
            'source_label' => CustomerInvoiceSource::Partload->label(),
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date->format('d-m-Y'),
            'billing_customer_name' => $invoice->billingCustomer?->name,
            'billing_customer_party_id' => $invoice->billing_customer_party_id,
            'lr_numbers' => $lrNumbers,
            'invoice_status' => $invoice->status,
            'payment_status' => $invoice->payment_status,
            'invoice_total' => $this->freightPresentation->presentAmount($invoice->invoice_total, $maySeeFreight),
            'balance_due' => $this->freightPresentation->presentAmount($invoice->balance_due, $maySeeFreight),
            'urls' => [
                'show' => route('branch.partload.invoices.show', [$invoice], absolute: false),
            ],
        ];
    }
}
