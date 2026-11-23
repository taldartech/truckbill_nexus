<?php

namespace App\Domains\Crm;

use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Exceptions\CrmDomainException;
use App\Domains\Identity\Enums\IdentityType;
use App\Domains\Identity\IdentityLifecycleService;
use App\Models\Company;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Support\Str;

/**
 * Qualified lead → Customer identity (reuse-safe) + company via PRD §7 services.
 */
final class LeadConversionService
{
    public function __construct(
        private readonly AuditedTransaction $auditedTransaction = new AuditedTransaction,
        private readonly AuditWriter $auditWriter = new AuditWriter,
        private readonly IdentityLifecycleService $identities = new IdentityLifecycleService,
        private readonly CompanyWorkspaceService $companies = new CompanyWorkspaceService,
    ) {}

    /**
     * @param  array<string, mixed>  $companyProfile  PRD §7 profile for CompanyWorkspaceService::createCompany
     * @return array{lead: Lead, user: User, company: Company, created_identity: bool}
     */
    public function convert(
        User $actor,
        Lead $lead,
        array $companyProfile,
        ?string $initialBranchName = 'Head Office',
        ?string $newPassword = null,
    ): array {
        if (! $actor->isInternal() || ! $actor->isActive()) {
            throw new CrmDomainException('Only active Internal users may convert leads.');
        }

        if ($lead->converted_at !== null || $lead->converted_company_id !== null || $lead->status === LeadStatus::Converted) {
            throw new CrmDomainException('This lead has already been converted.');
        }

        if ($lead->status !== LeadStatus::Qualified) {
            throw new CrmDomainException('Only Qualified leads can be converted.');
        }

        $email = trim((string) $lead->email);
        if ($email === '') {
            throw new CrmDomainException('A lead email is required for conversion.');
        }

        return $this->withPlatformTenant(function () use ($actor, $lead, $companyProfile, $initialBranchName, $newPassword, $email): array {
            return $this->auditedTransaction->run(function () use ($actor, $lead, $companyProfile, $initialBranchName, $newPassword, $email): array {
                $existing = User::query()->where('email', $email)->first();
                $createdIdentity = false;

                if ($existing instanceof User) {
                    if ($existing->isInternal()) {
                        throw new CrmDomainException('Cannot convert an Internal identity into a Customer identity.');
                    }
                    if (! $existing->isActive()) {
                        throw new CrmDomainException('Existing Customer identity is deactivated and cannot be reused.');
                    }
                    // Never overwrite credentials — IdentityLifecycleService enforces this.
                    $this->identities->updateWithoutOverwritingCredentials($existing, [
                        'name' => $lead->contact_name,
                    ]);
                    $customer = $existing->refresh();
                } else {
                    if ($newPassword === null || trim($newPassword) === '') {
                        $newPassword = Str::password(20);
                    }
                    $customer = $this->identities->create([
                        'name' => $lead->contact_name,
                        'email' => $email,
                        'password' => $newPassword,
                        'identity_type' => IdentityType::Customer,
                        'is_super_admin' => false,
                    ]);
                    $createdIdentity = true;
                }

                $company = $this->companies->createCompany(
                    $customer,
                    (string) ($companyProfile['name'] ?? $lead->company_name ?? $lead->contact_name.' Co'),
                    $initialBranchName,
                    $companyProfile,
                    requireCompleteProfile: true,
                );

                $before = [
                    'status' => $lead->status->value,
                    'converted_company_id' => $lead->converted_company_id,
                ];

                $lead->forceFill([
                    'status' => LeadStatus::Converted,
                    'converted_company_id' => $company->id,
                    'converted_user_id' => $customer->id,
                    'converted_at' => now(),
                ])->save();

                LeadActivity::query()->create([
                    'lead_id' => $lead->id,
                    'actor_user_id' => $actor->id,
                    'activity_type' => 'converted',
                    'body' => 'Lead converted to Customer company',
                    'meta' => [
                        'company_id' => $company->id,
                        'user_id' => $customer->id,
                        'created_identity' => $createdIdentity,
                    ],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->auditWriter->write(
                    AuditAction::Updated,
                    Lead::class,
                    $lead->id,
                    remark: 'Lead converted',
                    before: $before,
                    after: [
                        'status' => LeadStatus::Converted->value,
                        'converted_company_id' => $company->id,
                        'converted_user_id' => $customer->id,
                    ],
                );

                return [
                    'lead' => $lead->refresh(),
                    'user' => $customer,
                    'company' => $company,
                    'created_identity' => $createdIdentity,
                ];
            });
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPlatformTenant(callable $callback): mixed
    {
        $previous = TenantContextHolder::get();
        TenantContextHolder::set(new TenantContext(isInternal: true));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                TenantContextHolder::clear();
            } else {
                TenantContextHolder::set($previous);
            }
        }
    }
}
