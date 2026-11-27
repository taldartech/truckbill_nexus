<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Tax\GstRateService;
use App\Http\Controllers\Controller;
use App\Models\PlatformBillingProfile;
use App\Support\Audit\AuditEntry;
use App\Support\Files\PrivateFileService;
use App\Support\Files\StoredFile;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Internal Admin/CRM Company Billing Profiles Register (supports multiple billing entities).
 */
class PlatformBillingProfileController extends Controller
{
    public function __construct(
        private readonly PlatformBillingProfileService $profiles = new PlatformBillingProfileService,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
        private readonly GstRateService $gstRates = new GstRateService,
        private readonly PrivateFileService $files = new PrivateFileService,
    ) {}

    public function index(Request $request): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        $status = trim((string) $request->query('status', 'all'));
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }
        $search = trim((string) $request->query('q', ''));
        $canManage = $this->internalAuth->allows($request->user(), 'internal.subscription_billing.manage');

        $rows = $this->profiles->listAll($status, $search);

        return Inertia::render('admin/billing-profiles/index', [
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'canManage' => $canManage,
            'profiles' => $rows->map(fn (PlatformBillingProfile $profile): array => [
                'id' => $profile->id,
                'legal_name' => $profile->legal_name,
                'pan' => $profile->pan,
                'contact_number' => $profile->contact_number,
                'email' => $profile->email,
                'city' => $profile->city,
                'state_name' => $profile->state_name,
                'state_code' => $profile->state_code,
                'pin_code' => $profile->pin_code,
                'gstin' => $profile->gstin,
                'gst_rate_percent' => (string) $profile->gst_rate_percent,
                'bank_name' => $profile->bank_name,
                'has_logo' => $profile->logo_stored_file_id !== null || $profile->logo_path !== null,
                'logo_url' => ($profile->logo_stored_file_id !== null || $profile->logo_path !== null)
                    ? route('admin.billing-profiles.logo', $profile)
                    : null,
                'is_active' => (bool) $profile->is_active,
                'updated_at' => $profile->updated_at?->toIso8601String(),
                'show_url' => route('admin.billing-profiles.show', $profile),
                'edit_url' => $canManage ? route('admin.billing-profiles.edit', $profile) : null,
                'deactivate_url' => $canManage && $profile->is_active
                    ? route('admin.billing-profiles.deactivate', $profile)
                    : null,
                'reactivate_url' => $canManage && ! $profile->is_active
                    ? route('admin.billing-profiles.reactivate', $profile)
                    : null,
            ])->values()->all(),
            'links' => [
                'index' => route('admin.billing-profiles.index'),
                'create' => $canManage ? route('admin.billing-profiles.create') : null,
                'invoices' => route('admin.subscription-invoices'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');

        return Inertia::render('admin/billing-profiles/form', [
            'mode' => 'create',
            'profile' => null,
            'gstRates' => $this->gstRateOptions(),
            'links' => [
                'index' => route('admin.billing-profiles.index'),
                'submit' => route('admin.billing-profiles.store'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'pan' => ['required', 'string', 'max:10'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'contact_number' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'registered_address' => ['required', 'string', 'max:1000'],
            'state_id' => ['required', 'uuid'],
            'city_id' => ['required', 'uuid'],
            'pin_code' => ['required', 'string', 'max:12'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'gst_rate_id' => ['required', 'uuid'],
            'bank_name' => ['nullable', 'string', 'max:160'],
            'bank_account_name' => ['nullable', 'string', 'max:160'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_ifsc' => ['nullable', 'string', 'max:24'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes_terms' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $profile = $this->profiles->create($actor, $validated, $request->file('logo'));
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['billing_profile' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.billing-profiles.show', $profile)
            ->with('success', 'Billing Profile created.');
    }

    public function show(Request $request, PlatformBillingProfile $profile): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        $profile->load(['state:id,name,gst_code', 'cityRecord:id,name', 'gstRate:id,rate_percent,version', 'updatedBy:id,name,email']);
        $canManage = $this->internalAuth->allows($request->user(), 'internal.subscription_billing.manage');

        $audits = AuditEntry::query()
            ->where('subject_type', PlatformBillingProfile::class)
            ->where('subject_id', $profile->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AuditEntry $log): array => [
                'id' => (string) ($log->uuid ?: $log->id),
                'action' => $log->action,
                'remark' => $log->remark,
                'actor_id' => $log->actor_id,
                'created_at' => $log->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('admin/billing-profiles/show', [
            'profile' => $this->profilePayload($profile),
            'audits' => $audits,
            'canManage' => $canManage,
            'links' => [
                'index' => route('admin.billing-profiles.index'),
                'edit' => $canManage ? route('admin.billing-profiles.edit', $profile) : null,
                'deactivate' => $canManage && $profile->is_active ? route('admin.billing-profiles.deactivate', $profile) : null,
                'reactivate' => $canManage && ! $profile->is_active ? route('admin.billing-profiles.reactivate', $profile) : null,
                'invoices' => route('admin.subscription-invoices', ['billing_profile_id' => $profile->id]),
            ],
        ]);
    }

    public function edit(Request $request, PlatformBillingProfile $profile): Response
    {
        $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');
        $profile->load(['state:id,name,gst_code', 'cityRecord:id,name', 'gstRate:id,rate_percent,version', 'updatedBy:id,name,email']);

        return Inertia::render('admin/billing-profiles/form', [
            'mode' => 'edit',
            'profile' => $this->profilePayload($profile),
            'gstRates' => $this->gstRateOptions($profile),
            'links' => [
                'index' => route('admin.billing-profiles.index'),
                'show' => route('admin.billing-profiles.show', $profile),
                'submit' => route('admin.billing-profiles.update', $profile),
            ],
        ]);
    }

    public function update(Request $request, PlatformBillingProfile $profile): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'pan' => ['required', 'string', 'max:10'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'contact_number' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'registered_address' => ['required', 'string', 'max:1000'],
            'state_id' => ['required', 'uuid'],
            'city_id' => ['required', 'uuid'],
            'pin_code' => ['required', 'string', 'max:12'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'gst_rate_id' => ['required', 'uuid'],
            'bank_name' => ['nullable', 'string', 'max:160'],
            'bank_account_name' => ['nullable', 'string', 'max:160'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_ifsc' => ['nullable', 'string', 'max:24'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes_terms' => ['nullable', 'string', 'max:4000'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->profiles->update(
                $actor,
                $profile,
                $validated,
                $request->file('logo'),
                $validated['remark'],
            );
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['billing_profile' => $e->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.billing-profiles.show', $updated)
            ->with('success', 'Billing Profile updated. Historical approved invoices remain unchanged.');
    }

    public function deactivate(Request $request, PlatformBillingProfile $profile): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');
        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->profiles->deactivate($actor, $profile, $validated['remark']);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['billing_profile' => $e->getMessage()]);
        }

        return back()->with('success', 'Billing Profile deactivated. Historical invoice references remain intact.');
    }

    public function reactivate(Request $request, PlatformBillingProfile $profile): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');
        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->profiles->reactivate($actor, $profile, $validated['remark']);
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['billing_profile' => $e->getMessage()]);
        }

        return back()->with('success', 'Billing Profile reactivated.');
    }

    public function logo(Request $request, PlatformBillingProfile $profile): StreamedResponse
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        if ($profile->logo_stored_file_id !== null) {
            $file = StoredFile::query()->whereKey($profile->logo_stored_file_id)->first();
            if ($file !== null) {
                $previous = TenantContextHolder::get();
                TenantContextHolder::set(new TenantContext(isInternal: true));
                try {
                    return $this->files->downloadPlatformFile($file);
                } finally {
                    if ($previous === null) {
                        TenantContextHolder::clear();
                    } else {
                        TenantContextHolder::set($previous);
                    }
                }
            }
        }

        if ($profile->logo_path !== null && Storage::disk('local')->exists($profile->logo_path)) {
            return Storage::disk('local')->response($profile->logo_path);
        }

        abort(404);
    }

    public function legacyRedirect(): RedirectResponse
    {
        return redirect()->route('admin.billing-profiles.index');
    }

    /**
     * @return list<array{id: string, label: string, rate_percent: string}>
     */
    private function gstRateOptions(?PlatformBillingProfile $profile = null): array
    {
        $rates = $this->gstRates->listActive();
        $options = $rates->map(fn ($rate): array => [
            'id' => $rate->id,
            'label' => $rate->displayLabel(),
            'rate_percent' => (string) $rate->rate_percent,
        ])->values()->all();

        if ($profile !== null && $profile->gstRate !== null) {
            $already = collect($options)->contains('id', $profile->gstRate->id);
            if (! $already) {
                $options[] = [
                    'id' => $profile->gstRate->id,
                    'label' => $profile->gstRate->displayLabel().' (Historical)',
                    'rate_percent' => (string) $profile->gstRate->rate_percent,
                ];
            }
        }

        return array_values($options);
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(PlatformBillingProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'legal_name' => $profile->legal_name,
            'pan' => $profile->pan ?? '',
            'logo_url' => ($profile->logo_stored_file_id !== null || $profile->logo_path !== null)
                ? route('admin.billing-profiles.logo', $profile)
                : null,
            'contact_number' => $profile->contact_number,
            'email' => $profile->email,
            'registered_address' => $profile->registered_address,
            'city_id' => $profile->city_id ?? '',
            'city' => $profile->city,
            'state_id' => $profile->state_id ?? '',
            'state_name' => $profile->state_name,
            'state_code' => $profile->state_code ?? '',
            'pin_code' => $profile->pin_code,
            'gstin' => $profile->gstin ?? '',
            'gst_rate_id' => $profile->gst_rate_id ?? '',
            'gst_rate_percent' => (string) $profile->gst_rate_percent,
            'bank_name' => $profile->bank_name ?? '',
            'bank_account_name' => $profile->bank_account_name ?? '',
            'bank_account_number' => $profile->bank_account_number ?? '',
            'bank_ifsc' => $profile->bank_ifsc ?? '',
            'website' => $profile->website ?? '',
            'notes_terms' => $profile->notes_terms ?? '',
            'is_active' => (bool) $profile->is_active,
            'created_at' => $profile->created_at?->toIso8601String(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
            'updated_by' => $profile->updatedBy?->name,
        ];
    }
}
