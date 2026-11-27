<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Rbac\PermissionCodes;
use App\Domains\Subscription\Billing\PlatformBillingProfileService;
use App\Domains\Subscription\Catalogue\SubscriptionCatalogueAdminService;
use App\Domains\Subscription\Enums\SubscriptionModule;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Domains\Tax\GstRateService;
use App\Http\Controllers\Controller;
use App\Models\GstRate;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Admin/CRM Subscription Catalogue: module plans, add-ons and the common subscription GST rate.
 */
class SubscriptionCatalogueController extends Controller
{
    /** Sales (CRM) users may view pricing; only subscription billing managers may edit. */
    public const VIEW_PERMISSIONS = [
        PermissionCodes::INTERNAL_SUBSCRIPTION_BILLING_VIEW,
        PermissionCodes::INTERNAL_SUBSCRIPTION_BILLING_MANAGE,
        PermissionCodes::INTERNAL_CRM_VIEW,
    ];

    public function __construct(
        private readonly SubscriptionCatalogueAdminService $catalogue = new SubscriptionCatalogueAdminService,
        private readonly PlatformBillingProfileService $billingProfiles = new PlatformBillingProfileService,
        private readonly GstRateService $gstRates = new GstRateService,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->internalAuth->authorizeAnyOrAbort($request, self::VIEW_PERMISSIONS);
        $canManage = $this->internalAuth->allows($actor, PermissionCodes::INTERNAL_SUBSCRIPTION_BILLING_MANAGE);
        $catalogue = $this->catalogue->activeCatalogue();

        $plans = $catalogue === null ? collect() : SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->orderBy('module')
            ->orderBy('sort_order')
            ->get();
        $addons = $catalogue === null ? collect() : SubscriptionAddon::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->orderBy('sort_order')
            ->get();
        $taxSetting = $this->billingProfiles->subscriptionTaxSetting();

        return Inertia::render('admin/subscription-catalogue', [
            'canManage' => $canManage,
            'catalogue' => $catalogue === null ? null : [
                'code' => $catalogue->code,
                'name' => $catalogue->name,
                'effective_from' => $catalogue->effective_from?->toDateString(),
            ],
            'modules' => array_map(fn (SubscriptionModule $module): array => [
                'value' => $module->value,
                'label' => self::moduleLabel($module->value),
                'capacity_label' => self::capacityLabel($module->value),
            ], SubscriptionModule::cases()),
            'plans' => $plans->map(fn (SubscriptionPlan $plan): array => [
                'id' => $plan->id,
                'module' => $plan->module,
                'name' => $plan->name,
                'annual_price' => (string) $plan->annual_price,
                'capacity_units' => (int) $plan->capacity_units,
                'is_active' => (bool) $plan->is_active,
                'update_url' => $canManage ? route('admin.subscription-catalogue.plans.update', $plan) : null,
            ])->values()->all(),
            'addons' => $addons->map(fn (SubscriptionAddon $addon): array => [
                'id' => $addon->id,
                'code' => $addon->code,
                'name' => $addon->name,
                'annual_price' => (string) $addon->annual_price,
                'unit_label' => $addon->unit_label,
                'is_quantifiable' => (bool) $addon->is_quantifiable,
                'credit_units_per_unit' => (int) $addon->credit_units_per_unit > 0 ? (int) $addon->credit_units_per_unit : null,
                'storage_gb_per_unit' => (int) $addon->storage_bytes_per_unit > 0
                    ? intdiv((int) $addon->storage_bytes_per_unit, SubscriptionCatalogueAdminService::BYTES_PER_GB)
                    : null,
                'is_active' => (bool) $addon->is_active,
                'update_url' => $canManage ? route('admin.subscription-catalogue.addons.update', $addon) : null,
            ])->values()->all(),
            'pricingRules' => [
                'included_branches' => (int) config('subscription.capacity.included_branches', 2),
                'seats_per_included_branch' => (int) config('subscription.capacity.seats_per_included_branch', 3),
                'included_storage_gb' => intdiv((int) config('subscription.storage.default_allocation_bytes', 0), SubscriptionCatalogueAdminService::BYTES_PER_GB),
                'bundle_discounts' => [
                    ['label' => '1 module', 'percent' => '0'],
                    ['label' => '2 modules', 'percent' => '10'],
                    ['label' => '3 modules', 'percent' => '15'],
                ],
                'tenures' => array_map(fn (SubscriptionTenure $tenure): array => [
                    'value' => $tenure->value,
                    'label' => self::tenureLabel($tenure),
                    'discount_percent' => $tenure->discountPercent(),
                ], SubscriptionTenure::cases()),
            ],
            'gst' => [
                'gst_rate_id' => $taxSetting?->gst_rate_id,
                'gst_rate_percent' => $taxSetting !== null ? (string) $taxSetting->gst_rate_percent : null,
                'updated_at' => $taxSetting?->updated_at?->toIso8601String(),
                'options' => $this->gstRates->listActive()->map(fn (GstRate $rate): array => [
                    'id' => $rate->id,
                    'label' => $rate->displayLabel(),
                ])->values()->all(),
                'update_url' => $canManage ? route('admin.subscription-catalogue.gst.update') : null,
            ],
            'links' => [
                'calculator' => route('admin.subscription-calculator'),
                'store_plan' => $canManage ? route('admin.subscription-catalogue.plans.store') : null,
            ],
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SUBSCRIPTION_BILLING_MANAGE);
        $validated = $request->validate([
            'module' => ['required', Rule::enum(SubscriptionModule::class)],
            'name' => ['required', 'string', 'max:120'],
            'annual_price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'capacity_units' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);

        return $this->respond(
            fn () => $this->catalogue->createPlan($validated),
            'Plan created. It is available for new purchases.',
        );
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SUBSCRIPTION_BILLING_MANAGE);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'annual_price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'capacity_units' => ['required', 'integer', 'min:0', 'max:10000000'],
            'is_active' => ['required', 'boolean'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        return $this->respond(
            fn () => $this->catalogue->updatePlan($plan, $validated, $validated['remark']),
            'Plan updated. Existing contracts and invoices keep their original prices.',
        );
    }

    public function updateAddon(Request $request, SubscriptionAddon $addon): RedirectResponse
    {
        $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SUBSCRIPTION_BILLING_MANAGE);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'annual_price' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'credit_units_per_unit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'storage_gb_per_unit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['required', 'boolean'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        return $this->respond(
            fn () => $this->catalogue->updateAddon($addon, $validated, $validated['remark']),
            'Add-on updated. Existing contracts and invoices keep their original prices.',
        );
    }

    public function updateGst(Request $request): RedirectResponse
    {
        $actor = $this->internalAuth->authorizeOrAbort($request, PermissionCodes::INTERNAL_SUBSCRIPTION_BILLING_MANAGE);
        $validated = $request->validate([
            'gst_rate_id' => ['required', 'uuid'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $this->billingProfiles->updateSubscriptionGstRate($actor, $validated['gst_rate_id'], (string) ($validated['remark'] ?? ''));

        return back()->with('success', 'Subscription GST rate saved. It applies to future purchases only.');
    }

    public static function moduleLabel(string $module): string
    {
        return match ($module) {
            'broker' => 'Broker',
            'ownfleet' => 'OwnFleet',
            'partload' => 'Partload',
            default => ucfirst($module),
        };
    }

    public static function tenureLabel(SubscriptionTenure $tenure): string
    {
        return match ($tenure) {
            SubscriptionTenure::Monthly => 'Monthly',
            SubscriptionTenure::Quarterly => 'Quarterly',
            SubscriptionTenure::HalfYearly => 'Half-Yearly',
            SubscriptionTenure::Yearly => 'Yearly',
            SubscriptionTenure::ThreeYearFullAdvance => '3-Year Full Advance',
            SubscriptionTenure::ThreeYearAnnualInstalments => '3-Year Annual Instalments',
        };
    }

    private static function capacityLabel(string $module): string
    {
        return $module === 'ownfleet' ? 'Vehicles' : 'LR credits / year';
    }

    private function respond(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (SubscriptionDomainException $e) {
            return back()->withErrors(['catalogue' => $e->getMessage()]);
        }

        return back()->with('success', $successMessage);
    }
}
