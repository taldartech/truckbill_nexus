<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Enums\DataVisibility;
use App\Domains\Company\Enums\GstRegistrationType;
use App\Domains\Company\Enums\TaxLiability;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Tax\GstRateService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Owner company profile / settings hub — PRD §7 fields (D1 / PO-P1-P5-FULLSTACK-AUTH).
 */
class OwnerSettingsController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly CompanyWorkspaceService $workspace,
        private readonly GstRateService $gstRates,
    ) {}

    public function show(Request $request, Company $company): Response
    {
        ['user' => $user, 'scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $owner = User::query()->find($company->owner_user_id);

        return Inertia::render('owner/settings', [
            'company' => array_merge($this->companyPayload($company), [
                'pan' => $company->pan,
                'registered_address' => $company->registered_address,
                'state' => $company->state,
                'state_id' => $company->state_id,
                'city' => $company->city,
                'city_id' => $company->city_id,
                'pin' => $company->pin,
                'contact_person' => $company->contact_person,
                'mobile' => $company->mobile,
                'email' => $company->email,
                'gst_registration_type' => $company->gst_registration_type?->value,
                'tax_liability' => $company->tax_liability?->value,
                'gstin' => $company->gstin,
                'gst_rate_id' => $company->gst_rate_id,
                'data_visibility' => $company->data_visibility?->value,
                'timezone' => $company->timezone ?? 'Asia/Kolkata',
                'owner' => $owner === null ? null : [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                ],
                'updated_at' => $company->updated_at?->toIso8601String(),
            ]),
            'gstRates' => $this->gstRates->listActive()->map(fn ($rate): array => [
                'id' => $rate->id,
                'label' => $rate->displayLabel(),
                'rate_percent' => (string) $rate->rate_percent,
            ])->values(),
            'options' => [
                'gst_registration_types' => array_map(
                    fn (GstRegistrationType $case): array => ['value' => $case->value, 'label' => $case->name],
                    GstRegistrationType::cases(),
                ),
                'tax_liabilities' => array_map(
                    fn (TaxLiability $case): array => ['value' => $case->value, 'label' => strtoupper($case->value)],
                    TaxLiability::cases(),
                ),
                'data_visibilities' => [
                    ['value' => DataVisibility::SharedData->value, 'label' => 'Shared Data'],
                    ['value' => DataVisibility::BranchWiseRestricted->value, 'label' => 'Branch-wise Restricted'],
                ],
            ],
            'ownerPanelScope' => $scope,
            'canEditProfile' => $scope === CompanyAccessService::OWNER_PANEL_FULL,
            'deferredSections' => [
                [
                    'key' => 'numbering',
                    'title' => 'Numbering',
                    'message' => 'Document numbering is managed with each branch. A dedicated company numbering screen will be added later.',
                ],
                [
                    'key' => 'documents',
                    'title' => 'Documents, logo & signature',
                    'message' => 'Company logo and signature upload will be available in a later update.',
                ],
            ],
            'links' => [
                'dashboard' => route('owner.home'),
                'update' => route('owner.settings.update'),
            ],
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $profileSubmitted = $request->filled('pan')
            || $request->filled('registered_address')
            || $request->filled('gst_registration_type');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'remark' => ['required', 'string', 'max:1000'],
        ];

        if ($profileSubmitted || filled($company->pan)) {
            $rules = array_merge($rules, [
                'pan' => ['required', 'string', 'max:10'],
                'registered_address' => ['required', 'string', 'max:2000'],
                'state_id' => ['required', 'uuid', Rule::exists('states', 'id')->where(fn ($query) => $query->where('is_active', true))],
                'city_id' => ['required', 'uuid', Rule::exists('cities', 'id')->where(fn ($query) => $query->where('is_active', true))],
                'pin' => ['required', 'string', 'max:10'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'mobile' => ['required', 'string', 'max:20'],
                'email' => ['nullable', 'email', 'max:255'],
                'gst_registration_type' => ['required', Rule::enum(GstRegistrationType::class)],
                'tax_liability' => ['required', Rule::enum(TaxLiability::class)],
                'gstin' => ['nullable', 'string', 'max:15'],
                'gst_rate_id' => ['nullable', 'uuid', 'exists:gst_rates,id'],
                'data_visibility' => ['required', Rule::enum(DataVisibility::class)],
                'timezone' => ['nullable', 'string', 'max:64'],
            ]);
        }

        $validated = $request->validate($rules);

        try {
            $this->workspace->updateCompany(
                $company,
                $validated['name'],
                $validated['remark'],
                $profileSubmitted || filled($company->pan) ? $validated : [],
                requireCompleteProfile: $profileSubmitted || filled($company->pan),
            );
        } catch (CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.settings')
            ->with('success', 'Company profile updated.');
    }
}
