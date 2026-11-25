<?php

namespace App\Domains\Partload\Inward;

use App\Domains\Numbering\DocumentNumberAllocator;
use App\Domains\Numbering\Enums\DocumentNumberScopeType;
use App\Domains\Numbering\Enums\DocumentNumberType;
use App\Domains\Partload\Enums\PartloadChallanStatus;
use App\Domains\Partload\Enums\PartloadInwardDisposition;
use App\Domains\Partload\Exceptions\PartloadAuthorizationException;
use App\Domains\Partload\Exceptions\PartloadConflictException;
use App\Domains\Partload\Exceptions\PartloadDomainException;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Entitlement\CoreModuleEntitlementResolver;
use App\Domains\Subscription\Entitlement\SubscriptionEntitlementGate;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PartloadChallan;
use App\Models\PartloadChallanLine;
use App\Models\PartloadInwardLine;
use App\Models\PartloadInwardRegister;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One Inward Register per incoming Dispatched Challan (Rules §12).
 */
final class PartloadInwardService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly CoreModuleEntitlementResolver $entitlements = new CoreModuleEntitlementResolver,
        private readonly SubscriptionEntitlementGate $entitlementGate = new SubscriptionEntitlementGate,
        private readonly DocumentNumberAllocator $numbers = new DocumentNumberAllocator,
    ) {}

    public function findForBranch(Company $company, Branch $branch, string $inwardId): PartloadInwardRegister
    {
        $this->ensureTenantMatches($company, $branch);

        $inward = PartloadInwardRegister::query()
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->whereNull('deleted_at')
            ->with(['challan', 'fromStation', 'toStation', 'lines.lorryReceipt'])
            ->find($inwardId);

        if ($inward === null) {
            throw new PartloadDomainException('Inward Register was not found in this branch.');
        }

        return $inward;
    }

    /**
     * @param  array{
     *     partload_challan_id: string,
     *     inward_date?: string|null,
     *     remark?: string|null,
     *     lines?: list<array{lorry_receipt_id: string, disposition?: string, packages?: numeric-string|float|int|null, weight_kg?: numeric-string|float|int|null, remark?: string|null}>
     * }  $payload
     */
    public function createFromChallan(Company $company, Branch $branch, User $actor, array $payload): PartloadInwardRegister
    {
        $this->assertEntitled($company);
        $this->assertPermission($actor, $company, $branch, PermissionCodes::PARTLOAD_INWARD_CREATE);
        $this->ensureTenantMatches($company, $branch);

        $challan = PartloadChallan::query()
            ->where('company_id', $company->id)
            ->whereKey((string) $payload['partload_challan_id'])
            ->whereNull('deleted_at')
            ->with(['lines' => fn ($q) => $q->whereNull('deleted_at')])
            ->first();

        if ($challan === null) {
            throw ValidationException::withMessages([
                'partload_challan_id' => 'Challan was not found.',
            ]);
        }

        if ($challan->status !== PartloadChallanStatus::Dispatched) {
            throw new PartloadConflictException('Only Dispatched Challans can be received into an Inward Register.');
        }

        if ($challan->inwardRegister()->whereNull('deleted_at')->exists()) {
            throw new PartloadConflictException('This Challan already has an Inward Register.');
        }

        $lineOverrides = collect($payload['lines'] ?? [])->keyBy('lorry_receipt_id');

        try {
            return $this->auditedTransaction->run(function () use (
                $company,
                $branch,
                $actor,
                $payload,
                $challan,
                $lineOverrides,
            ): PartloadInwardRegister {
                return $this->withTenant($company, $branch, function () use (
                    $company,
                    $branch,
                    $actor,
                    $payload,
                    $challan,
                    $lineOverrides,
                ): PartloadInwardRegister {
                    $allocation = $this->numbers->allocateNext(
                        companyId: $company->id,
                        documentType: DocumentNumberType::PartloadInward,
                        scopeType: DocumentNumberScopeType::Branch,
                        branchId: $branch->id,
                    );

                    $inward = PartloadInwardRegister::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'branch_id' => $branch->id,
                        'inward_number' => $allocation['number'],
                        'inward_date' => filled($payload['inward_date'] ?? null)
                            ? CarbonImmutable::parse((string) $payload['inward_date'])->toDateString()
                            : now()->toDateString(),
                        'partload_challan_id' => $challan->id,
                        'from_station_id' => $challan->from_station_id,
                        'to_station_id' => $challan->to_station_id,
                        'vehicle_id' => $challan->vehicle_id,
                        'driver_party_id' => $challan->driver_party_id,
                        'status' => 'received',
                        'remark' => filled($payload['remark'] ?? null) ? trim((string) $payload['remark']) : null,
                        'created_by_user_id' => $actor->id,
                    ]);

                    $position = 1;
                    /** @var PartloadChallanLine $challanLine */
                    foreach ($challan->lines as $challanLine) {
                        $override = $lineOverrides->get($challanLine->lorry_receipt_id);
                        $destinationMatches = $challanLine->to_station_id === $challan->to_station_id;
                        $disposition = PartloadInwardDisposition::Delivery;
                        if (is_array($override) && filled($override['disposition'] ?? null)) {
                            $disposition = PartloadInwardDisposition::from((string) $override['disposition']);
                        } elseif (! $destinationMatches) {
                            $disposition = PartloadInwardDisposition::Forward;
                        }

                        PartloadInwardLine::query()->create([
                            'id' => (string) Str::uuid(),
                            'company_id' => $company->id,
                            'partload_inward_register_id' => $inward->id,
                            'lorry_receipt_id' => $challanLine->lorry_receipt_id,
                            'partload_challan_line_id' => $challanLine->id,
                            'position' => $position++,
                            'packages' => number_format((float) (is_array($override) ? ($override['packages'] ?? $challanLine->packages) : $challanLine->packages), 3, '.', ''),
                            'weight_kg' => number_format((float) (is_array($override) ? ($override['weight_kg'] ?? $challanLine->weight_kg) : $challanLine->weight_kg), 3, '.', ''),
                            'disposition' => $disposition,
                            'remark' => is_array($override) && filled($override['remark'] ?? null)
                                ? trim((string) $override['remark'])
                                : null,
                        ]);
                    }

                    $challan->forceFill([
                        'status' => PartloadChallanStatus::Received,
                    ])->save();

                    $this->audit->write(
                        AuditAction::Created,
                        PartloadInwardRegister::class,
                        $inward->id,
                        remark: 'Partload Inward Register created',
                        before: null,
                        after: [
                            'inward_number' => $inward->inward_number,
                            'challan_id' => $challan->id,
                        ],
                    );

                    return $inward->fresh(['lines', 'challan']) ?? $inward;
                });
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new PartloadConflictException(
                'An Inward Register already exists for this Challan.',
                previous: $e,
            );
        }
    }

    private function assertEntitled(Company $company): void
    {
        $this->entitlementGate->assertBranchMutationAllowed($company);

        if (! $this->entitlements->isEntitled($company, SubscriptionModule::Partload)) {
            throw new PartloadAuthorizationException('Partload module is not entitled for this company.');
        }
    }

    private function assertPermission(User $actor, Company $company, Branch $branch, string $code): void
    {
        if ((int) $actor->id === (int) $company->owner_user_id) {
            return;
        }

        if (! $this->authorizer->allowsCustomer($actor, $code, $company, $branch)) {
            throw new PartloadAuthorizationException('You are not authorized for this Partload action.');
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new PartloadDomainException('Branch does not belong to company.');
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(Company $company, Branch $branch, callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::inject(new TenantContext(
            companyId: $company->id,
            branchId: $branch->id,
            ownerId: (string) $company->owner_user_id,
            isInternal: false,
            isOwnerScope: false,
        ));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::inject($previous);
            }
        }
    }
}
