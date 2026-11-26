<?php

namespace App\Domains\Subscription\Billing;

use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\Company;
use App\Models\PlatformBillingProfile;
use App\Models\State;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manages the TruckBill service provider's billing profile and authoritative subscription GST calculations.
 */
final class PlatformBillingProfileService
{
    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    public function current(): ?PlatformBillingProfile
    {
        return PlatformBillingProfile::currentActive();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsert(User $actor, array $payload, ?UploadedFile $logo = null, ?string $remark = null): PlatformBillingProfile
    {
        return $this->tx->run(function () use ($actor, $payload, $logo, $remark): PlatformBillingProfile {
            $previous = TenantContextHolder::get();
            TenantContextHolder::set(new TenantContext(
                companyId: null,
                branchId: null,
                ownerId: null,
                isInternal: true,
                isOwnerScope: false,
            ));

            try {
                $existing = PlatformBillingProfile::query()
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                $stateName = trim((string) ($payload['state_name'] ?? ''));
                $stateCode = strtoupper(trim((string) ($payload['state_code'] ?? '')));
                $stateId = filled($payload['state_id'] ?? null) ? (string) $payload['state_id'] : null;

                if ($stateId !== null) {
                    $state = State::query()->whereKey($stateId)->first();
                    if ($state === null) {
                        throw ValidationException::withMessages(['state_id' => 'Selected state is invalid.']);
                    }
                    $stateName = $state->name;
                    $stateCode = strtoupper((string) $state->gst_code);
                } elseif ($stateCode === '' && $stateName !== '') {
                    $matched = State::query()
                        ->whereRaw('LOWER(name) = ?', [strtolower($stateName)])
                        ->orWhereRaw('LOWER(code) = ?', [strtolower($stateName)])
                        ->first();
                    if ($matched !== null) {
                        $stateId = $matched->id;
                        $stateName = $matched->name;
                        $stateCode = strtoupper((string) $matched->gst_code);
                    }
                }

                $gstin = filled($payload['gstin'] ?? null) ? strtoupper(trim((string) $payload['gstin'])) : null;
                if ($gstin !== null && $stateCode === '' && preg_match('/^([0-9]{2})/', $gstin, $matches) === 1) {
                    $stateCode = $matches[1];
                }

                $logoPath = $existing?->logo_path;
                if ($logo !== null) {
                    $logoPath = $logo->store('platform-billing', 'local');
                } elseif (! empty($payload['remove_logo']) && $logoPath !== null) {
                    Storage::disk('local')->delete($logoPath);
                    $logoPath = null;
                }

                $attributes = [
                    'legal_name' => trim((string) $payload['legal_name']),
                    'trade_name' => filled($payload['trade_name'] ?? null) ? trim((string) $payload['trade_name']) : null,
                    'registered_address' => trim((string) $payload['registered_address']),
                    'state_id' => $stateId,
                    'state_name' => $stateName,
                    'state_code' => $stateCode !== '' ? $stateCode : null,
                    'city' => trim((string) $payload['city']),
                    'pin_code' => trim((string) $payload['pin_code']),
                    'gstin' => $gstin,
                    'pan' => filled($payload['pan'] ?? null) ? strtoupper(trim((string) $payload['pan'])) : null,
                    'contact_number' => trim((string) $payload['contact_number']),
                    'email' => strtolower(trim((string) $payload['email'])),
                    'logo_path' => $logoPath,
                    'bank_name' => filled($payload['bank_name'] ?? null) ? trim((string) $payload['bank_name']) : null,
                    'bank_account_name' => filled($payload['bank_account_name'] ?? null) ? trim((string) $payload['bank_account_name']) : null,
                    'bank_account_number' => filled($payload['bank_account_number'] ?? null) ? trim((string) $payload['bank_account_number']) : null,
                    'bank_ifsc' => filled($payload['bank_ifsc'] ?? null) ? strtoupper(trim((string) $payload['bank_ifsc'])) : null,
                    'bank_branch' => filled($payload['bank_branch'] ?? null) ? trim((string) $payload['bank_branch']) : null,
                    'gst_rate_percent' => number_format((float) ($payload['gst_rate_percent'] ?? 0), 2, '.', ''),
                    'sac_code' => trim((string) ($payload['sac_code'] ?? '998314')),
                    'hsn_code' => filled($payload['hsn_code'] ?? null) ? trim((string) $payload['hsn_code']) : null,
                    'invoice_prefix' => strtoupper(trim((string) ($payload['invoice_prefix'] ?? 'TB'))),
                    'invoice_next_sequence' => max(1, (int) ($payload['invoice_next_sequence'] ?? ($existing !== null ? $existing->invoice_next_sequence : 1))),
                    'credit_note_prefix' => strtoupper(trim((string) ($payload['credit_note_prefix'] ?? 'TBCN'))),
                    'credit_note_next_sequence' => max(1, (int) ($payload['credit_note_next_sequence'] ?? ($existing !== null ? $existing->credit_note_next_sequence : 1))),
                    'is_active' => true,
                    'updated_by_user_id' => $actor->id,
                ];

                if ($existing === null) {
                    $profile = PlatformBillingProfile::query()->create(array_merge([
                        'id' => (string) Str::uuid(),
                    ], $attributes));

                    $this->audit->write(
                        action: AuditAction::Created,
                        subjectType: PlatformBillingProfile::class,
                        subjectId: $profile->id,
                        remark: $remark ?? 'Configure platform billing profile',
                        before: null,
                        after: $profile->toArray(),
                    );

                    return $profile;
                }

                $before = $existing->toArray();
                $existing->fill($attributes);
                $existing->save();

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: PlatformBillingProfile::class,
                    subjectId: $existing->id,
                    remark: $remark ?? 'Update platform billing profile',
                    before: $before,
                    after: $existing->fresh()?->toArray(),
                );

                return $existing->fresh() ?? $existing;
            } finally {
                if ($previous === null) {
                    TenantContextHolder::clear();
                } else {
                    TenantContextHolder::set($previous);
                }
            }
        });
    }

    /**
     * Authoritative customer billing snapshot resolved solely from Company master.
     *
     * @return array<string, mixed>
     */
    public function customerSnapshot(Company $company): array
    {
        [$stateName, $stateCode] = $this->resolveCompanyState($company);

        return [
            'company_id' => $company->id,
            'legal_name' => $company->name,
            'registered_address' => $company->registered_address,
            'state' => $stateName,
            'state_code' => $stateCode,
            'city' => $company->city,
            'pin_code' => $company->pin,
            'gstin' => $company->gstin,
            'pan' => $company->pan,
            'contact_person' => $company->contact_person,
            'contact_number' => $company->mobile,
            'email' => $company->email,
            'gst_registration_type' => $company->gst_registration_type?->value,
        ];
    }

    /**
     * Compute subscription GST strictly from configured PlatformBillingProfile and Company place of supply.
     *
     * @return array{
     *     configured: bool,
     *     gst_rate_percent: string,
     *     sac_code: string,
     *     place_of_supply_state: string|null,
     *     place_of_supply_code: string|null,
     *     is_inter_state: bool,
     *     taxable_amount: string,
     *     cgst_amount: string,
     *     sgst_amount: string,
     *     igst_amount: string,
     *     total_tax_amount: string,
     *     grand_total: string
     * }
     */
    public function computeTaxForCompany(Company $company, string $taxableAmount, ?PlatformBillingProfile $profile = null): array
    {
        $taxable = DecimalMath::of($taxableAmount, 2);
        $activeProfile = $profile ?? $this->current();

        [$customerState, $customerCode] = $this->resolveCompanyState($company);

        if ($activeProfile === null) {
            return [
                'configured' => false,
                'gst_rate_percent' => '0.00',
                'sac_code' => '998314',
                'place_of_supply_state' => $customerState,
                'place_of_supply_code' => $customerCode,
                'is_inter_state' => false,
                'taxable_amount' => $taxable,
                'cgst_amount' => '0.00',
                'sgst_amount' => '0.00',
                'igst_amount' => '0.00',
                'total_tax_amount' => '0.00',
                'grand_total' => $taxable,
            ];
        }

        $rate = DecimalMath::of((string) $activeProfile->gst_rate_percent, 2);
        $supplierCode = $this->resolveProfileStateCode($activeProfile);
        $isInterState = $supplierCode !== null
            && $customerCode !== null
            && $supplierCode !== $customerCode;

        $hasSupplierGst = filled($activeProfile->gstin) && DecimalMath::compare($rate, '0', 2) > 0;
        if (! $hasSupplierGst || DecimalMath::compare($taxable, '0', 2) <= 0) {
            return [
                'configured' => true,
                'gst_rate_percent' => $rate,
                'sac_code' => $activeProfile->sac_code,
                'place_of_supply_state' => $customerState ?? $activeProfile->state_name,
                'place_of_supply_code' => $customerCode ?? $supplierCode,
                'is_inter_state' => $isInterState,
                'taxable_amount' => $taxable,
                'cgst_amount' => '0.00',
                'sgst_amount' => '0.00',
                'igst_amount' => '0.00',
                'total_tax_amount' => '0.00',
                'grand_total' => $taxable,
            ];
        }

        $totalTax = DecimalMath::percentOf($taxable, $rate, 2);
        if ($isInterState) {
            $cgst = '0.00';
            $sgst = '0.00';
            $igst = $totalTax;
        } else {
            $half = DecimalMath::div($totalTax, '2', 2);
            $cgst = $half;
            $sgst = DecimalMath::sub($totalTax, $half, 2);
            $igst = '0.00';
        }

        $grandTotal = DecimalMath::add($taxable, $totalTax, 2);

        return [
            'configured' => true,
            'gst_rate_percent' => $rate,
            'sac_code' => $activeProfile->sac_code,
            'place_of_supply_state' => $customerState ?? $activeProfile->state_name,
            'place_of_supply_code' => $customerCode ?? $supplierCode,
            'is_inter_state' => $isInterState,
            'taxable_amount' => $taxable,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'igst_amount' => $igst,
            'total_tax_amount' => $totalTax,
            'grand_total' => $grandTotal,
        ];
    }

    public function assertConfiguredForProductionTaxInvoice(): PlatformBillingProfile
    {
        $profile = $this->current();
        if ($profile === null) {
            throw new SubscriptionDomainException('Platform billing profile must be configured in Admin/CRM before issuing tax invoices.');
        }

        return $profile;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveCompanyState(Company $company): array
    {
        $stateName = filled($company->state) ? trim((string) $company->state) : null;
        $stateCode = null;

        if (filled($company->state_id)) {
            $state = State::query()->whereKey($company->state_id)->first();
            if ($state !== null) {
                $stateName = $state->name;
                $stateCode = strtoupper((string) $state->gst_code);
            }
        }

        if ($stateCode === null && filled($company->gstin) && preg_match('/^([0-9]{2})/', strtoupper((string) $company->gstin), $matches) === 1) {
            $stateCode = $matches[1];
        }

        if ($stateCode === null && $stateName !== null) {
            $matched = State::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($stateName)])
                ->orWhereRaw('LOWER(code) = ?', [strtolower($stateName)])
                ->first();
            if ($matched !== null) {
                $stateName = $matched->name;
                $stateCode = strtoupper((string) $matched->gst_code);
            }
        }

        return [$stateName, $stateCode];
    }

    private function resolveProfileStateCode(PlatformBillingProfile $profile): ?string
    {
        if (filled($profile->state_code)) {
            return strtoupper(trim((string) $profile->state_code));
        }

        if (filled($profile->state_id)) {
            $state = State::query()->whereKey($profile->state_id)->first();
            if ($state !== null && filled($state->gst_code)) {
                return strtoupper((string) $state->gst_code);
            }
        }

        if (filled($profile->gstin) && preg_match('/^([0-9]{2})/', strtoupper((string) $profile->gstin), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
