<?php

namespace App\Domains\Enquiry;

use App\Domains\Enquiry\Exceptions\EnquiryAuthorizationException;
use App\Domains\Enquiry\Exceptions\EnquiryDomainException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Enquiry;
use App\Models\EnquiryFollowUp;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Enquiry follow-up scheduling (Phase 4 Batch C).
 */
final class EnquiryFollowUpService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function add(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        User $actor,
        array $payload,
    ): EnquiryFollowUp {
        $this->assertEnquiryInBranch($company, $branch, $enquiry);

        $remarks = trim((string) ($payload['remarks'] ?? ''));
        if ($remarks === '') {
            throw ValidationException::withMessages(['remarks' => 'Follow-up remarks are required.']);
        }

        $followUpAt = $this->parseDateTime($payload['follow_up_at'] ?? null, 'follow_up_at');

        return $this->auditedTransaction->run(function () use ($company, $branch, $enquiry, $actor, $remarks, $followUpAt): EnquiryFollowUp {
            return $this->withTenant($company, $branch, function () use ($company, $enquiry, $actor, $remarks, $followUpAt): EnquiryFollowUp {
                $row = EnquiryFollowUp::query()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'enquiry_id' => $enquiry->id,
                    'follow_up_at' => $followUpAt,
                    'remarks' => $remarks,
                    'actor_user_id' => $actor->id,
                    'completed_at' => null,
                ]);

                $this->audit->write(
                    AuditAction::Created,
                    EnquiryFollowUp::class,
                    $row->id,
                    remark: 'Enquiry follow-up added',
                    before: null,
                    after: [
                        'enquiry_id' => $enquiry->id,
                        'follow_up_at' => $followUpAt->toIso8601String(),
                    ],
                );

                return $row;
            });
        });
    }

    public function complete(
        Company $company,
        Branch $branch,
        Enquiry $enquiry,
        EnquiryFollowUp $followUp,
        User $actor,
    ): EnquiryFollowUp {
        $this->assertEnquiryInBranch($company, $branch, $enquiry);

        if ($followUp->company_id !== $company->id || $followUp->enquiry_id !== $enquiry->id) {
            throw new EnquiryAuthorizationException('Follow-up does not belong to this enquiry.');
        }

        if ($followUp->completed_at !== null) {
            throw new EnquiryDomainException('Follow-up is already completed.');
        }

        return $this->auditedTransaction->run(function () use ($company, $branch, $followUp): EnquiryFollowUp {
            return $this->withTenant($company, $branch, function () use ($followUp): EnquiryFollowUp {
                $before = $followUp->toArray();
                $followUp->forceFill(['completed_at' => now()])->save();

                $this->audit->write(
                    AuditAction::Updated,
                    EnquiryFollowUp::class,
                    $followUp->id,
                    remark: 'Follow-up completed',
                    before: $before,
                    after: $followUp->fresh()?->toArray(),
                );

                return $followUp->fresh();
            });
        });
    }

    private function assertEnquiryInBranch(Company $company, Branch $branch, Enquiry $enquiry): void
    {
        if ($enquiry->company_id !== $company->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this company.');
        }

        if ($enquiry->branch_id !== null && $enquiry->branch_id !== $branch->id) {
            throw new EnquiryAuthorizationException('Enquiry does not belong to this branch.');
        }

        $this->ensureTenantMatches($company, $branch);
    }

    private function parseDateTime(mixed $value, string $field): Carbon
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([$field => 'Follow-up date and time are required.']);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Invalid follow-up date/time.']);
        }
    }

    private function ensureTenantMatches(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            throw new EnquiryDomainException('Branch does not belong to company.');
        }

        $tenant = TenantContextHolder::require();
        if ($tenant->companyId !== $company->id || ($tenant->branchId !== null && $tenant->branchId !== $branch->id)) {
            throw new EnquiryAuthorizationException('Tenant context mismatch.');
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
