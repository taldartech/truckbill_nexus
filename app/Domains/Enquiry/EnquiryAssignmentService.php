<?php

namespace App\Domains\Enquiry;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Enquiry\Enums\EnquiryStatus;
use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryAssignmentHistory;
use App\Models\EnquiryStatusHistory;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Outbox\OutboxWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Branch/user assignment and reassignment for enquiries (Phase 4 Batch C).
 */
final class EnquiryAssignmentService
{
    public function __construct(
        private readonly CompanyAccessService $access = new CompanyAccessService,
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly OutboxWriter $outbox = new OutboxWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assign(
        Company $company,
        Branch $contextBranch,
        Enquiry $enquiry,
        User $actor,
        array $payload,
    ): Enquiry {
        if ($enquiry->company_id !== $company->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this company.');
        }

        $this->ensureTenantMatches($company, $contextBranch);

        if (in_array($enquiry->status, [
            EnquiryStatus::Converted,
            EnquiryStatus::Rejected,
            EnquiryStatus::Cancelled,
        ], true)) {
            throw new EnquiryDomainException('Enquiry cannot be reassigned in its current status.');
        }

        $remark = trim((string) ($payload['remark'] ?? ''));
        if ($remark === '') {
            throw ValidationException::withMessages(['remark' => 'A remark is required for assignment.']);
        }

        $toBranchId = $payload['to_branch_id'] ?? null;
        $toUserId = $payload['to_assigned_user_id'] ?? null;

        if ($toBranchId === null && $toUserId === null) {
            throw ValidationException::withMessages([
                'to_branch_id' => 'Assign a branch and/or user.',
            ]);
        }

        $toBranch = null;
        if ($toBranchId !== null) {
            $toBranch = Branch::query()
                ->where('company_id', $company->id)
                ->whereKey((string) $toBranchId)
                ->whereNull('deactivated_at')
                ->whereNull('deleted_at')
                ->first();
            if ($toBranch === null) {
                throw ValidationException::withMessages(['to_branch_id' => 'Target branch was not found in this company.']);
            }
        } else {
            $toBranch = $enquiry->branch_id !== null
                ? Branch::query()->where('company_id', $company->id)->whereKey($enquiry->branch_id)->first()
                : $contextBranch;
        }

        if ($toBranch === null) {
            throw ValidationException::withMessages(['to_branch_id' => 'Branch assignment is required before assigning a user.']);
        }

        $toUser = null;
        if ($toUserId !== null) {
            $toUser = User::query()->whereKey((int) $toUserId)->first();
            if ($toUser === null || ! $toUser->isCustomer() || ! $toUser->isActive()) {
                throw ValidationException::withMessages(['to_assigned_user_id' => 'Assigned user is not valid.']);
            }

            if (! $this->access->canAccessBranch($toUser, $company, $toBranch)) {
                throw ValidationException::withMessages(['to_assigned_user_id' => 'User is not assigned to the target branch.']);
            }
        }

        $canSeeUnassigned = $enquiry->branch_id === null;
        if ($enquiry->branch_id !== null
            && $enquiry->branch_id !== $contextBranch->id
            && $enquiry->branch_id !== $toBranch->id) {
            throw new EnquiryAuthorizationException('Enquiry is not visible in this branch context.');
        }

        if ($canSeeUnassigned && $enquiry->branch_id === null && $toBranch === null) {
            throw ValidationException::withMessages(['to_branch_id' => 'Unassigned enquiry requires a branch assignment.']);
        }

        return $this->auditedTransaction->run(function () use (
            $company,
            $toBranch,
            $enquiry,
            $actor,
            $remark,
            $toUser,
        ): Enquiry {
            return $this->withTenant($company, $toBranch, function () use (
                $company,
                $toBranch,
                $enquiry,
                $actor,
                $remark,
                $toUser,
            ): Enquiry {
                /** @var Enquiry $locked */
                $locked = Enquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();

                $fromBranchId = $locked->branch_id;
                $fromUserId = $locked->assigned_user_id;

                EnquiryAssignmentHistory::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'enquiry_id' => $locked->id,
                    'from_branch_id' => $fromBranchId,
                    'to_branch_id' => $toBranch->id,
                    'from_assigned_user_id' => $fromUserId,
                    'to_assigned_user_id' => $toUser?->id,
                    'actor_user_id' => $actor->id,
                    'remark' => $remark,
                    'assigned_at' => now(),
                ]);

                $previousStatus = $locked->status;
                $nextStatus = $previousStatus === EnquiryStatus::New
                    ? EnquiryStatus::UnderReview
                    : $previousStatus;

                $locked->forceFill([
                    'branch_id' => $toBranch->id,
                    'assigned_user_id' => $toUser?->id,
                    'status' => $nextStatus,
                ])->save();

                if ($nextStatus !== $previousStatus) {
                    EnquiryStatusHistory::query()->create([
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'enquiry_id' => $locked->id,
                        'from_status' => $previousStatus,
                        'to_status' => $nextStatus,
                        'actor_user_id' => $actor->id,
                        'remark' => 'Status updated on assignment',
                        'changed_at' => now(),
                    ]);
                }

                $fresh = $locked->fresh(['assignmentHistories', 'assignedUser']);

                $this->audit->write(
                    AuditAction::Updated,
                    Enquiry::class,
                    $locked->id,
                    remark: $remark,
                    before: [
                        'branch_id' => $fromBranchId,
                        'assigned_user_id' => $fromUserId,
                    ],
                    after: [
                        'branch_id' => $toBranch->id,
                        'assigned_user_id' => $toUser?->id,
                        'status' => $nextStatus->value,
                    ],
                );

                $this->outbox->enqueue(
                    eventType: 'enquiry.assigned',
                    payload: [
                        'enquiry_id' => $locked->id,
                        'enquiry_number' => $locked->enquiry_number,
                        'from_branch_id' => $fromBranchId,
                        'to_branch_id' => $toBranch->id,
                        'from_assigned_user_id' => $fromUserId,
                        'to_assigned_user_id' => $toUser?->id,
                    ],
                );

                return $fresh;
            });
        });
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new EnquiryDomainException('Branch does not belong to company.');
        }

        $tenant = TenantContextHolder::require();
        if ($tenant->companyId !== $company->id) {
            throw new EnquiryAuthorizationException('Tenant company context mismatch.');
        }

        if ($tenant->branchId !== null && $tenant->branchId !== $branch->id) {
            throw new EnquiryAuthorizationException('Tenant branch context mismatch.');
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
