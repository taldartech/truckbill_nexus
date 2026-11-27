<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Rbac\InternalAuthorization;
use App\Domains\Subscription\Catalogue\SubscriptionCalculatorService;
use App\Domains\Subscription\Catalogue\SubscriptionCatalogueAdminService;
use App\Domains\Subscription\Enums\SubscriptionTenure;
use App\Domains\Subscription\Exceptions\SubscriptionDomainException;
use App\Http\Controllers\Controller;
use App\Models\State;
use App\Models\SubscriptionAddon;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Admin/CRM subscription calculator — pricing only, no company, contract or payment.
 */
class SubscriptionCalculatorController extends Controller
{
    private const PRICED_BY_REQUIREMENT = ['extra_branch', 'extra_user', 'lr_credits_broker', 'lr_credits_partload', 'extra_storage'];

    public function __construct(
        private readonly SubscriptionCalculatorService $calculator = new SubscriptionCalculatorService,
        private readonly SubscriptionCatalogueAdminService $catalogue = new SubscriptionCatalogueAdminService,
        private readonly InternalAuthorization $internalAuth = new InternalAuthorization,
    ) {}

    public function index(Request $request): Response
    {
        $this->internalAuth->authorizeAnyOrAbort($request, SubscriptionCatalogueController::VIEW_PERMISSIONS);
        $catalogue = $this->catalogue->activeCatalogue();

        $plans = $catalogue === null ? collect() : SubscriptionPlan::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->orderBy('module')
            ->orderBy('sort_order')
            ->get();
        $featureAddons = $catalogue === null ? collect() : SubscriptionAddon::query()
            ->where('catalogue_version_id', $catalogue->id)
            ->where('is_active', true)
            ->whereNotIn('code', self::PRICED_BY_REQUIREMENT)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('admin/subscription-calculator', [
            'modules' => collect(['broker', 'ownfleet', 'partload'])->map(fn (string $module): array => [
                'value' => $module,
                'label' => SubscriptionCatalogueController::moduleLabel($module),
                'plans' => $plans->where('module', $module)->map(fn (SubscriptionPlan $plan): array => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'annual_price' => (string) $plan->annual_price,
                    'capacity_units' => (int) $plan->capacity_units,
                ])->values()->all(),
            ])->values()->all(),
            'addons' => $featureAddons->map(fn (SubscriptionAddon $addon): array => [
                'id' => $addon->id,
                'code' => $addon->code,
                'name' => $addon->name,
                'annual_price' => (string) $addon->annual_price,
            ])->values()->all(),
            'tenures' => array_map(fn (SubscriptionTenure $tenure): array => [
                'value' => $tenure->value,
                'label' => SubscriptionCatalogueController::tenureLabel($tenure),
                'discount_percent' => $tenure->discountPercent(),
            ], SubscriptionTenure::cases()),
            'states' => State::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (State $state): array => ['id' => $state->id, 'name' => $state->name])->values()->all(),
            'included' => [
                'branches' => (int) config('subscription.capacity.included_branches', 2),
                'users' => (int) config('subscription.capacity.included_branches', 2) * (int) config('subscription.capacity.seats_per_included_branch', 3),
                'storage_gb' => intdiv((int) config('subscription.storage.default_allocation_bytes', 0), SubscriptionCatalogueAdminService::BYTES_PER_GB),
            ],
            'links' => [
                'quote' => route('admin.subscription-calculator.quote'),
                'catalogue' => route('admin.subscription-catalogue'),
            ],
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $this->internalAuth->authorizeAnyOrAbort($request, SubscriptionCatalogueController::VIEW_PERMISSIONS);
        $validated = $request->validate([
            'plan_ids' => ['nullable', 'array', 'max:3'],
            'plan_ids.*' => ['uuid'],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['uuid'],
            'tenure' => ['required', Rule::enum(SubscriptionTenure::class)],
            'total_branches' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'total_users' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'broker_lr_credits' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'partload_lr_credits' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'total_storage_gb' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'state_id' => ['nullable', 'uuid'],
        ]);

        try {
            return response()->json($this->calculator->calculate($validated));
        } catch (SubscriptionDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
