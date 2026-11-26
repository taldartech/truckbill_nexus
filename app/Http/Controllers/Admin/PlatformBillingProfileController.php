<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Http\Controllers\Controller;
use App\Models\PlatformBillingProfile;
use App\Models\State;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Internal Admin/CRM Platform Billing Profile configuration for TruckBill service provider.
 */
class PlatformBillingProfileController extends Controller
{
    public function __construct(
        private readonly PlatformBillingProfileService $profiles = new PlatformBillingProfileService,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
    ) {}

    public function index(Request $request): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        $profile = $this->profiles->current();
        $states = State::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'gst_code']);

        return Inertia::render('admin/billing-profile', [
            'profile' => $profile === null ? null : [
                'id' => $profile->id,
                'legal_name' => $profile->legal_name,
                'trade_name' => $profile->trade_name,
                'registered_address' => $profile->registered_address,
                'state_id' => $profile->state_id,
                'state_name' => $profile->state_name,
                'state_code' => $profile->state_code,
                'city' => $profile->city,
                'pin_code' => $profile->pin_code,
                'gstin' => $profile->gstin,
                'pan' => $profile->pan,
                'contact_number' => $profile->contact_number,
                'email' => $profile->email,
                'logo_url' => $profile->logo_path !== null ? route('admin.billing-profile.logo') : null,
                'bank_name' => $profile->bank_name,
                'bank_account_name' => $profile->bank_account_name,
                'bank_account_number' => $profile->bank_account_number,
                'bank_ifsc' => $profile->bank_ifsc,
                'bank_branch' => $profile->bank_branch,
                'gst_rate_percent' => (string) $profile->gst_rate_percent,
                'sac_code' => $profile->sac_code,
                'hsn_code' => $profile->hsn_code,
                'invoice_prefix' => $profile->invoice_prefix,
                'invoice_next_sequence' => $profile->invoice_next_sequence,
                'credit_note_prefix' => $profile->credit_note_prefix,
                'credit_note_next_sequence' => $profile->credit_note_next_sequence,
                'updated_at' => $profile->updated_at?->toIso8601String(),
            ],
            'states' => $states->map(fn (State $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'gst_state_code' => $s->gst_code,
            ])->values()->all(),
            'canManage' => $this->internalAuth->allows($request->user(), 'internal.subscription_billing.manage'),
            'links' => [
                'save' => route('admin.billing-profile.update'),
                'invoices' => route('admin.subscription-invoices'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, 'internal.subscription_billing.manage');

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'registered_address' => ['required', 'string', 'max:1000'],
            'state_id' => ['nullable', 'uuid'],
            'state_name' => ['nullable', 'string', 'max:120'],
            'state_code' => ['nullable', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:120'],
            'pin_code' => ['required', 'string', 'max:12'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'pan' => ['nullable', 'string', 'max:10'],
            'contact_number' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'bank_ifsc' => ['nullable', 'string', 'max:20'],
            'bank_branch' => ['nullable', 'string', 'max:150'],
            'gst_rate_percent' => ['required', 'numeric', 'min:0', 'max:40'],
            'sac_code' => ['required', 'string', 'max:20'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'invoice_prefix' => ['required', 'string', 'max:24'],
            'invoice_next_sequence' => ['required', 'integer', 'min:1'],
            'credit_note_prefix' => ['required', 'string', 'max:24'],
            'credit_note_next_sequence' => ['required', 'integer', 'min:1'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->profiles->upsert(
                $actor,
                $validated,
                $request->file('logo'),
                $validated['remark'],
            );
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['billing_profile' => $e->getMessage()]);
        }

        return back()->with('success', 'Platform billing profile saved. Previously issued invoices remain unchanged.');
    }

    public function logo(Request $request): StreamedResponse
    {
        $this->internalAuth->authorizeAnyOrAbort($request, [
            'internal.subscription_billing.view',
            'internal.subscription_billing.manage',
        ]);

        /** @var PlatformBillingProfile|null $profile */
        $profile = $this->profiles->current();
        if ($profile === null || $profile->logo_path === null || ! Storage::disk('local')->exists($profile->logo_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($profile->logo_path);
    }
}
