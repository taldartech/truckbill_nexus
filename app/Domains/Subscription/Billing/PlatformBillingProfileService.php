<?php

namespace App\Domains\Subscription\Billing;

use App\Domains\Company\CompanyProfileValidator;
use App\Domains\Geography\GeographyLookupService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Subscription\Support\DecimalMath;
use App\Models\Company;
use App\Models\GstRate;
use App\Models\PlatformBillingProfile;
use App\Models\State;
use App\Models\SubscriptionTaxSetting;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditedTransaction;
use App\Support\Audit\AuditWriter;
use App\Support\Files\Exceptions\FileNotAvailableException;
use App\Support\Files\Exceptions\FileValidationException;
use App\Support\Files\PrivateFileService;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manages multiple Company Billing Profiles and authoritative subscription GST calculations.
 */
final class PlatformBillingProfileService
{
    public const IFSC_PATTERN = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    public function __construct(
        private readonly AuditedTransaction $tx = new AuditedTransaction,
        private readonly AuditWriter $audit = new AuditWriter,
        private readonly GeographyLookupService $geography = new GeographyLookupService,
        private readonly PrivateFileService $files = new PrivateFileService,
    ) {}

    public function current(): ?PlatformBillingProfile
    {
        return PlatformBillingProfile::currentActive();
    }

    public function subscriptionTaxSetting(): ?SubscriptionTaxSetting
    {
        return SubscriptionTaxSetting::current();
    }

    /**
     * Common GST rate applied to future subscription purchases across all Billing Profiles.
     */
    public function subscriptionGstRatePercent(): string
    {
        $setting = $this->subscriptionTaxSetting();

        return DecimalMath::of((string) ($setting->gst_rate_percent ?? '0'), 2);
    }

    /**
     * Configure the common subscription GST rate. Changes apply to future quotes only;
     * purchase, payment-intent and invoice snapshots keep the rate they were priced with.
     */
    public function updateSubscriptionGstRate(User $actor, string $gstRateId, string $remark = ''): SubscriptionTaxSetting
    {
        $gstRate = $this->resolveEffectiveGstRate($gstRateId);

        return $this->tx->run(function () use ($actor, $gstRate, $remark): SubscriptionTaxSetting {
            return $this->withPlatformTenant(function () use ($actor, $gstRate, $remark): SubscriptionTaxSetting {
                $existing = SubscriptionTaxSetting::query()->orderByDesc('updated_at')->lockForUpdate()->first();
                $trimmedRemark = trim($remark);
                if ($existing !== null && $trimmedRemark === '') {
                    throw ValidationException::withMessages([
                        'remark' => 'An edit remark is required when changing the subscription GST rate.',
                    ]);
                }

                $before = $existing?->toArray();
                $setting = $existing ?? new SubscriptionTaxSetting;
                $setting->fill([
                    'gst_rate_id' => $gstRate->id,
                    'gst_rate_percent' => DecimalMath::of((string) $gstRate->rate_percent, 2),
                    'updated_by_user_id' => $actor->id,
                ]);
                $setting->save();

                $this->audit->write(
                    action: $existing === null ? AuditAction::Created : AuditAction::Updated,
                    subjectType: SubscriptionTaxSetting::class,
                    subjectId: $setting->id,
                    remark: $existing === null ? 'Configure subscription GST rate' : $trimmedRemark,
                    before: $before,
                    after: $setting->toArray(),
                );

                return $setting->fresh(['gstRate', 'updatedBy']) ?? $setting;
            });
        });
    }

    /**
     * @return Collection<int, PlatformBillingProfile>
     */
    public function listAll(?string $status = 'all', ?string $search = null): Collection
    {
        $query = PlatformBillingProfile::query()
            ->with(['state:id,name,gst_code', 'cityRecord:id,name', 'updatedBy:id,name,email'])
            ->orderByDesc('is_active')
            ->orderBy('legal_name');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $trimmed = trim((string) $search);
        if ($trimmed !== '') {
            $like = '%'.mb_strtolower($trimmed).'%';
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(legal_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(gstin, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(pan, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(city) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(state_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            });
        }

        return $query->get();
    }

    /**
     * @return Collection<int, PlatformBillingProfile>
     */
    public function listActive(): Collection
    {
        return PlatformBillingProfile::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with(['state:id,name,gst_code', 'cityRecord:id,name'])
            ->orderBy('legal_name')
            ->get();
    }

    /**
     * Create a new Company Billing Profile (no creation remark required).
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload, ?UploadedFile $logo = null): PlatformBillingProfile
    {
        return $this->tx->run(function () use ($actor, $payload, $logo): PlatformBillingProfile {
            return $this->withPlatformTenant(function () use ($actor, $payload, $logo): PlatformBillingProfile {
                $attributes = $this->validateAndNormalize($payload);

                if ($logo !== null) {
                    $storedLogo = $this->storeLogoUpload($logo);
                    $attributes['logo_stored_file_id'] = $storedLogo->id;
                    $attributes['logo_path'] = $storedLogo->path;
                }

                $profile = PlatformBillingProfile::query()->create(array_merge([
                    'id' => (string) Str::uuid(),
                    'is_active' => true,
                    'updated_by_user_id' => $actor->id,
                ], $attributes));

                $this->audit->write(
                    action: AuditAction::Created,
                    subjectType: PlatformBillingProfile::class,
                    subjectId: $profile->id,
                    remark: 'Create company billing profile '.$profile->legal_name,
                    before: null,
                    after: $profile->toArray(),
                );

                return $profile->fresh(['state', 'cityRecord', 'logoFile', 'updatedBy']) ?? $profile;
            });
        });
    }

    /**
     * Update an existing Company Billing Profile (mandatory edit remark, historical invoices remain intact).
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(
        User $actor,
        PlatformBillingProfile $profile,
        array $payload,
        ?UploadedFile $logo = null,
        string $remark = '',
    ): PlatformBillingProfile {
        $trimmedRemark = trim($remark);
        if ($trimmedRemark === '') {
            throw ValidationException::withMessages([
                'remark' => 'An edit remark is required when updating a Billing Profile.',
            ]);
        }

        return $this->tx->run(function () use ($actor, $profile, $payload, $logo, $trimmedRemark): PlatformBillingProfile {
            return $this->withPlatformTenant(function () use ($actor, $profile, $payload, $logo, $trimmedRemark): PlatformBillingProfile {
                /** @var PlatformBillingProfile $locked */
                $locked = PlatformBillingProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                $before = $locked->toArray();

                $attributes = $this->validateAndNormalize($payload);

                if ($logo !== null) {
                    $storedLogo = $this->storeLogoUpload($logo);
                    $attributes['logo_stored_file_id'] = $storedLogo->id;
                    $attributes['logo_path'] = $storedLogo->path;
                } elseif (! empty($payload['remove_logo'])) {
                    $attributes['logo_stored_file_id'] = null;
                    $attributes['logo_path'] = null;
                }

                $locked->fill(array_merge($attributes, [
                    'updated_by_user_id' => $actor->id,
                ]));
                $locked->save();

                $fresh = $locked->fresh(['state', 'cityRecord', 'logoFile', 'updatedBy']) ?? $locked;

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: PlatformBillingProfile::class,
                    subjectId: $locked->id,
                    remark: $trimmedRemark,
                    before: $before,
                    after: $fresh->toArray(),
                );

                return $fresh;
            });
        });
    }

    public function deactivate(User $actor, PlatformBillingProfile $profile, string $remark): PlatformBillingProfile
    {
        return $this->toggleStatus($actor, $profile, false, $remark);
    }

    public function reactivate(User $actor, PlatformBillingProfile $profile, string $remark): PlatformBillingProfile
    {
        return $this->toggleStatus($actor, $profile, true, $remark);
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
     * Compute subscription GST from the common subscription GST rate (or a preserved snapshot rate),
     * the Billing Profile supplier registration and the Company place of supply.
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
    public function computeTaxForCompany(
        Company $company,
        string $taxableAmount,
        ?PlatformBillingProfile $profile = null,
        ?string $snapshotRatePercent = null,
    ): array {
        $taxable = DecimalMath::of($taxableAmount, 2);
        $activeProfile = $profile ?? $this->current();
        $defaultSac = (string) config('subscription.billing.default_sac_code', '998314');

        [$customerState, $customerCode] = $this->resolveCompanyState($company);

        if ($activeProfile === null) {
            return [
                'configured' => false,
                'gst_rate_percent' => '0.00',
                'sac_code' => $defaultSac,
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

        $rate = $snapshotRatePercent !== null
            ? DecimalMath::of($snapshotRatePercent, 2)
            : $this->subscriptionGstRatePercent();
        $supplierCode = $this->resolveProfileStateCode($activeProfile);
        $isInterState = $supplierCode !== null
            && $customerCode !== null
            && $supplierCode !== $customerCode;

        $hasSupplierGst = filled($activeProfile->gstin) && DecimalMath::compare($rate, '0', 2) > 0;
        if (! $hasSupplierGst || DecimalMath::compare($taxable, '0', 2) <= 0) {
            return [
                'configured' => true,
                'gst_rate_percent' => $hasSupplierGst ? $rate : '0.00',
                'sac_code' => $defaultSac,
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
            'sac_code' => $defaultSac,
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validateAndNormalize(array $payload): array
    {
        $legalName = trim((string) ($payload['legal_name'] ?? ''));
        if ($legalName === '') {
            throw ValidationException::withMessages(['legal_name' => 'Company Name is required.']);
        }

        $pan = strtoupper(trim((string) ($payload['pan'] ?? '')));
        if ($pan === '' || ! preg_match(CompanyProfileValidator::PAN_PATTERN, $pan)) {
            throw ValidationException::withMessages(['pan' => 'A valid PAN is required (e.g. AABCT1234F).']);
        }

        $digits = preg_replace('/\D+/', '', (string) ($payload['contact_number'] ?? '')) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (! preg_match(CompanyProfileValidator::MOBILE_PATTERN, $digits)) {
            throw ValidationException::withMessages(['contact_number' => 'A valid 10-digit Indian Contact Number is required.']);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => 'A valid Email address is required.']);
        }

        $registeredAddress = trim((string) ($payload['registered_address'] ?? ''));
        if ($registeredAddress === '') {
            throw ValidationException::withMessages(['registered_address' => 'Registered Address is required.']);
        }

        $stateId = filled($payload['state_id'] ?? null) ? (string) $payload['state_id'] : null;
        $cityId = filled($payload['city_id'] ?? null) ? (string) $payload['city_id'] : null;

        if ($stateId === null) {
            throw ValidationException::withMessages(['state_id' => 'State must be selected from the State Master.']);
        }
        if ($cityId === null) {
            throw ValidationException::withMessages(['city_id' => 'City must be selected from the City Master.']);
        }

        $resolvedGeography = $this->geography->resolveAddressFields($stateId, $cityId);
        $stateModel = State::query()->whereKey($resolvedGeography['state_id'])->firstOrFail();
        $stateCode = strtoupper((string) $stateModel->gst_code);

        $pinCode = trim((string) ($payload['pin_code'] ?? ''));
        if (! preg_match(CompanyProfileValidator::PIN_PATTERN, $pinCode)) {
            throw ValidationException::withMessages(['pin_code' => 'A valid 6-digit PIN Code is required.']);
        }

        $gstin = filled($payload['gstin'] ?? null) ? strtoupper(trim((string) $payload['gstin'])) : null;

        if ($gstin !== null) {
            if (! preg_match(CompanyProfileValidator::GSTIN_PATTERN, $gstin)) {
                throw ValidationException::withMessages(['gstin' => 'GSTIN format is invalid (expected 15-character GSTIN).']);
            }
            if ($stateCode !== '' && substr($gstin, 0, 2) !== $stateCode) {
                throw ValidationException::withMessages(['gstin' => 'GSTIN state code prefix ('.substr($gstin, 0, 2).') must match selected State GST code ('.$stateCode.').']);
            }
        }

        $bankName = filled($payload['bank_name'] ?? null) ? trim((string) $payload['bank_name']) : null;
        $bankAccountName = filled($payload['bank_account_name'] ?? null) ? trim((string) $payload['bank_account_name']) : null;
        $bankAccountNumber = filled($payload['bank_account_number'] ?? null) ? trim((string) $payload['bank_account_number']) : null;
        $bankIfsc = filled($payload['bank_ifsc'] ?? null) ? strtoupper(trim((string) $payload['bank_ifsc'])) : null;

        $bankProvidedCount = count(array_filter(
            [$bankName, $bankAccountName, $bankAccountNumber, $bankIfsc],
            static fn (?string $value): bool => $value !== null,
        ));
        if ($bankProvidedCount > 0 && $bankProvidedCount < 4) {
            throw ValidationException::withMessages([
                'bank_name' => 'Bank Name, Account Holder Name, Account Number, and IFSC Code must all be provided together.',
            ]);
        }
        if ($bankIfsc !== null && ! preg_match(self::IFSC_PATTERN, $bankIfsc)) {
            throw ValidationException::withMessages(['bank_ifsc' => 'IFSC Code must follow the 11-character format (e.g. HDFC0001234).']);
        }

        $website = filled($payload['website'] ?? null) ? trim((string) $payload['website']) : null;
        if ($website !== null) {
            if (filter_var($website, FILTER_VALIDATE_URL) === false || ! preg_match('/^https?:\/\//i', $website)) {
                throw ValidationException::withMessages(['website' => 'Website must be a valid URL starting with http:// or https://.']);
            }
        }

        $notesTerms = filled($payload['notes_terms'] ?? null) ? trim((string) $payload['notes_terms']) : null;

        return [
            'legal_name' => $legalName,
            'pan' => $pan,
            'contact_number' => $digits,
            'email' => $email,
            'registered_address' => $registeredAddress,
            'state_id' => $resolvedGeography['state_id'],
            'state_name' => (string) $resolvedGeography['state'],
            'state_code' => $stateCode !== '' ? $stateCode : null,
            'city_id' => $resolvedGeography['city_id'],
            'city' => (string) $resolvedGeography['city'],
            'pin_code' => $pinCode,
            'gstin' => $gstin,
            'bank_name' => $bankName,
            'bank_account_name' => $bankAccountName,
            'bank_account_number' => $bankAccountNumber,
            'bank_ifsc' => $bankIfsc,
            'website' => $website,
            'notes_terms' => $notesTerms,
        ];
    }

    private function resolveEffectiveGstRate(string $gstRateId): GstRate
    {
        $today = Carbon::today();
        $gstRate = $gstRateId === '' ? null : GstRate::query()
            ->whereKey($gstRateId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            })
            ->first();

        if ($gstRate === null) {
            throw ValidationException::withMessages([
                'gst_rate_id' => 'Select an active GST Rate from the GST Master.',
            ]);
        }

        return $gstRate;
    }

    private function storeLogoUpload(UploadedFile $logo): StoredFile
    {
        try {
            return $this->files->storePlatformImage($logo);
        } catch (FileValidationException|FileNotAvailableException $e) {
            throw ValidationException::withMessages([
                'logo' => $e->getMessage(),
            ]);
        }
    }

    private function toggleStatus(User $actor, PlatformBillingProfile $profile, bool $active, string $remark): PlatformBillingProfile
    {
        $trimmedRemark = trim($remark);
        if ($trimmedRemark === '') {
            throw ValidationException::withMessages([
                'remark' => 'A remark is required for this lifecycle action.',
            ]);
        }

        return $this->tx->run(function () use ($actor, $profile, $active, $trimmedRemark): PlatformBillingProfile {
            return $this->withPlatformTenant(function () use ($actor, $profile, $active, $trimmedRemark): PlatformBillingProfile {
                /** @var PlatformBillingProfile $locked */
                $locked = PlatformBillingProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

                if ((bool) $locked->is_active === $active) {
                    throw new SubscriptionDomainException(
                        $active ? 'Billing Profile is already active.' : 'Billing Profile is already inactive.'
                    );
                }

                $before = $locked->toArray();
                $locked->forceFill([
                    'is_active' => $active,
                    'updated_by_user_id' => $actor->id,
                ])->save();

                $fresh = $locked->fresh(['state', 'cityRecord', 'logoFile', 'updatedBy']) ?? $locked;

                $this->audit->write(
                    action: AuditAction::Updated,
                    subjectType: PlatformBillingProfile::class,
                    subjectId: $locked->id,
                    remark: $trimmedRemark,
                    before: $before,
                    after: $fresh->toArray(),
                );

                return $fresh;
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
        TenantContextHolder::set(new TenantContext(
            companyId: null,
            branchId: null,
            ownerId: null,
            isInternal: true,
            isOwnerScope: false,
        ));

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
