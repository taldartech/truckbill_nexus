<?php

namespace App\Domains\Consignment;

use App\Domains\Consignment\Enums\LrStatus;
use App\Domains\Consignment\Enums\LrWorkflowContext;
use App\Domains\Consignment\Exceptions\LorryReceiptDomainException;
use App\Domains\OwnFleet\Enums\TripStatus;
use App\Domains\OwnFleet\Trip\Exceptions\TripConflictException;
use App\Domains\OwnFleet\Trip\Exceptions\TripDomainException;
use App\Domains\OwnFleet\Trip\TripService;
use App\Domains\Rbac\PermissionAuthorizer;
use App\Domains\Rbac\PermissionCodes;
use App\Models\Branch;
use App\Models\Company;
use App\Models\LorryReceipt;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Unified Bilty operational status transitions (OwnFleet + Broker sync helpers).
 */
final class LorryReceiptOperationalStatusService
{
    public function __construct(
        private readonly TripService $trips = new TripService,
        private readonly PermissionAuthorizer $authorizer = new PermissionAuthorizer,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array{
     *     status: string,
     *     starting_odometer?: string|null,
     *     ending_odometer?: string|null,
     *     departure_at?: string|null,
     *     actual_arrival_at?: string|null,
     *     remark?: string|null
     * }  $payload
     */
    public function changeStatus(
        LorryReceipt $lorryReceipt,
        User $actor,
        array $payload,
    ): LorryReceipt {
        $company = Company::query()->findOrFail($lorryReceipt->company_id);
        $branch = Branch::query()->findOrFail($lorryReceipt->branch_id);

        if (! $this->authorizer->allowsCustomer($actor, PermissionCodes::LR_EDIT, $company, $branch)) {
            throw new LorryReceiptDomainException('Not authorized to change Bilty status.');
        }

        $target = LrStatus::tryFrom((string) $payload['status']);
        if ($target === null) {
            throw ValidationException::withMessages(['status' => 'Invalid status.']);
        }

        $current = $lorryReceipt->status;
        if ($current === $target) {
            return $lorryReceipt;
        }

        if ($lorryReceipt->workflow_context === LrWorkflowContext::OwnFleet) {
            return $this->changeOwnFleetStatus($lorryReceipt, $actor, $company, $branch, $current, $target, $payload);
        }

        throw ValidationException::withMessages([
            'status' => 'Manual status change is only supported for Own Vehicle Bilties. Broker status follows Hire, Delivery and Invoice.',
        ]);
    }

    public function markInProgressFromHire(LorryReceipt $lorryReceipt, User $actor): LorryReceipt
    {
        if ($lorryReceipt->status !== LrStatus::Pending) {
            return $lorryReceipt;
        }

        return $this->forceStatus($lorryReceipt, $actor, LrStatus::InProgress, 'Lorry Hire created — Bilty In Progress');
    }

    public function markInvoiced(LorryReceipt $lorryReceipt, User $actor): LorryReceipt
    {
        if ($lorryReceipt->status === LrStatus::Invoiced || $lorryReceipt->status === LrStatus::Cancelled) {
            return $lorryReceipt;
        }

        if ($lorryReceipt->status !== LrStatus::Delivered) {
            throw ValidationException::withMessages([
                'status' => 'Only Delivered Bilties can become Invoiced.',
            ]);
        }

        return $this->forceStatus($lorryReceipt, $actor, LrStatus::Invoiced, 'Customer Invoice created — Bilty Invoiced');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function changeOwnFleetStatus(
        LorryReceipt $lorryReceipt,
        User $actor,
        Company $company,
        Branch $branch,
        LrStatus $current,
        LrStatus $target,
        array $payload,
    ): LorryReceipt {
        $allowed = match ($current) {
            LrStatus::Pending => [LrStatus::InProgress],
            LrStatus::InProgress => [LrStatus::Delivered],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Cannot change status from %s to %s.',
                    $current->label(),
                    $target->label(),
                ),
            ]);
        }

        $trip = $this->trips->tripForLorryReceipt($lorryReceipt);
        if ($trip === null) {
            throw ValidationException::withMessages([
                'status' => 'Own Vehicle Bilty has no internal Trip context. Contact support.',
            ]);
        }

        return $this->auditedTransaction->run(function () use (
            $lorryReceipt,
            $actor,
            $company,
            $branch,
            $current,
            $target,
            $payload,
            $trip,
        ): LorryReceipt {
            if ($target === LrStatus::InProgress) {
                if ($trip->status === TripStatus::Pending) {
                    try {
                        $this->trips->dispatch($trip, $actor, [
                            'starting_odometer' => (string) ($payload['starting_odometer'] ?? ''),
                            'departure_at' => $payload['departure_at'] ?? null,
                        ]);
                    } catch (ValidationException $e) {
                        throw $e;
                    } catch (TripConflictException|TripDomainException $e) {
                        throw ValidationException::withMessages(['starting_odometer' => $e->getMessage()]);
                    }
                }
            }

            if ($target === LrStatus::Delivered) {
                $freshTrip = $trip->fresh();
                if ($freshTrip !== null && $freshTrip->status === TripStatus::InProgress) {
                    try {
                        $this->trips->complete($freshTrip, $actor, [
                            'ending_odometer' => (string) ($payload['ending_odometer'] ?? ''),
                            'actual_arrival_at' => $payload['actual_arrival_at'] ?? null,
                        ]);
                    } catch (ValidationException $e) {
                        throw $e;
                    } catch (TripConflictException|TripDomainException $e) {
                        throw ValidationException::withMessages(['ending_odometer' => $e->getMessage()]);
                    }
                } elseif ($freshTrip === null || $freshTrip->status !== TripStatus::Completed) {
                    throw ValidationException::withMessages([
                        'status' => 'Trip must be In Progress or Completed before marking Delivered.',
                    ]);
                }
            }

            return $this->applyStatus(
                $lorryReceipt,
                $actor,
                $company,
                $branch,
                $current,
                $target,
                filled($payload['remark'] ?? null) ? (string) $payload['remark'] : null,
            );
        });
    }

    private function forceStatus(
        LorryReceipt $lorryReceipt,
        User $actor,
        LrStatus $target,
        string $remark,
    ): LorryReceipt {
        $company = Company::query()->findOrFail($lorryReceipt->company_id);
        $branch = Branch::query()->findOrFail($lorryReceipt->branch_id);

        return $this->auditedTransaction->run(function () use ($lorryReceipt, $actor, $company, $branch, $target, $remark): LorryReceipt {
            return $this->applyStatus(
                $lorryReceipt,
                $actor,
                $company,
                $branch,
                $lorryReceipt->status,
                $target,
                $remark,
            );
        });
    }

    private function applyStatus(
        LorryReceipt $lorryReceipt,
        User $actor,
        Company $company,
        Branch $branch,
        LrStatus $before,
        LrStatus $after,
        ?string $remark,
    ): LorryReceipt {
        return $this->withTenant($company, $branch, function () use ($lorryReceipt, $before, $after, $remark): LorryReceipt {
            $updates = [
                'status' => $after,
            ];

            if ($after === LrStatus::Delivered && $lorryReceipt->delivered_at === null) {
                $updates['delivered_at'] = CarbonImmutable::now('UTC');
            }

            $lorryReceipt->forceFill($updates)->save();

            $this->audit->write(
                AuditAction::Updated,
                LorryReceipt::class,
                $lorryReceipt->id,
                remark: $remark ?? ('Bilty status '.$before->value.' → '.$after->value),
                before: ['status' => $before->value],
                after: ['status' => $after->value],
            );

            return $lorryReceipt->fresh(['vehicle', 'trip']) ?? $lorryReceipt;
        });
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withTenant(Company $company, Branch $branch, \Closure $callback): mixed
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
