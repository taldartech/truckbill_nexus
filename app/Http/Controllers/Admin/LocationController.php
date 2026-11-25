<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditWriter;
use App\Support\Database\CaseInsensitiveLike;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Team Platform → State Master and City Master.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    /**
     * Legacy combined locations URL — redirect to State Master.
     */
    public function index(Request $request): RedirectResponse
    {
        $this->assertPlatformOperator($request);

        return redirect()->route('admin.states.index');
    }

    public function statesIndex(Request $request): Response
    {
        $this->assertPlatformOperator($request);

        $search = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        if ($status === '') {
            $status = 'all';
        }

        $query = State::query()->orderBy('name');

        if ($search !== '') {
            CaseInsensitiveLike::whereAnyContains($query, ['name', 'code', 'gst_code'], $search);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $paginator = $query->withCount('cities')->paginate(25)->withQueryString();

        return Inertia::render('admin/states', [
            'note' => 'Platform State Master. GST state codes are centrally governed. Ordinary Owners cannot alter these records.',
            'filters' => [
                'q' => $search,
                'status' => $status,
            ],
            'states' => $paginator->getCollection()->map(fn (State $state): array => [
                'id' => $state->id,
                'name' => $state->name,
                'code' => $state->code,
                'gst_code' => $state->gst_code,
                'country_code' => $state->country_code,
                'cities_count' => (int) $state->cities_count,
                'is_active' => $state->is_active,
                'toggle_url' => route('admin.states.toggle', $state),
            ])->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'urls' => [
                'index' => route('admin.states.index'),
                'store' => route('admin.states.store'),
            ],
        ]);
    }

    public function storeState(Request $request): RedirectResponse|JsonResponse
    {
        $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:16', Rule::unique('states', 'code')],
            'gst_code' => ['required', 'string', 'max:2', 'regex:/^[0-9]{2}$/', Rule::unique('states', 'gst_code')],
            'country_code' => ['nullable', 'string', 'size:2'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $state = $this->withPlatformTenant(function () use ($validated): State {
            $created = State::query()->create([
                'name' => trim($validated['name']),
                'code' => strtoupper(trim($validated['code'])),
                'gst_code' => str_pad(trim($validated['gst_code']), 2, '0', STR_PAD_LEFT),
                'country_code' => strtoupper(trim((string) ($validated['country_code'] ?? 'IN'))) ?: 'IN',
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->audit->write(
                AuditAction::Created,
                State::class,
                $created->id,
                remark: 'State created via admin UI',
                before: null,
                after: $created->toArray(),
            );

            return $created;
        });

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $state->id,
                    'name' => $state->name,
                    'code' => $state->code,
                    'gst_code' => $state->gst_code,
                ],
            ], 201);
        }

        return back()->with('success', 'State “'.$state->name.'” created.');
    }

    public function toggleState(Request $request, State $state): RedirectResponse
    {
        $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
        ]);

        $this->withPlatformTenant(function () use ($state, $validated): void {
            $before = $state->toArray();
            $state->forceFill([
                'is_active' => ! $state->is_active,
            ])->save();

            $this->audit->write(
                AuditAction::Updated,
                State::class,
                $state->id,
                remark: $validated['remark'],
                before: $before,
                after: $state->fresh()?->toArray(),
            );
        });

        return back()->with(
            'success',
            $state->is_active
                ? 'State reactivated. Historical references remain intact.'
                : 'State deactivated. Historical references remain intact.',
        );
    }

    public function citiesIndex(Request $request): Response
    {
        $this->assertPlatformOperator($request);

        $search = trim($request->string('q')->toString());
        $stateId = trim($request->string('state_id')->toString());
        $status = trim($request->string('status')->toString());
        if ($status === '') {
            $status = 'all';
        }

        $query = City::query()
            ->with('state:id,name')
            ->orderBy('name');

        if ($stateId !== '') {
            $query->where('state_id', $stateId);
        }

        if ($search !== '') {
            CaseInsensitiveLike::whereAnyContains($query, ['name'], $search);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $paginator = $query->paginate(25)->withQueryString();

        $states = State::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (State $state): array => [
                'id' => $state->id,
                'name' => $state->name,
            ])
            ->values()
            ->all();

        return Inertia::render('admin/cities', [
            'note' => 'Platform City Master. Newly created cities become available in geography selectors immediately.',
            'filters' => [
                'q' => $search,
                'state_id' => $stateId,
                'status' => $status,
            ],
            'states' => $states,
            'cities' => $paginator->getCollection()->map(fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name,
                'state_name' => $city->state !== null ? $city->state->name : '—',
                'state_id' => $city->state_id,
                'is_active' => $city->is_active,
                'toggle_url' => route('admin.cities.toggle', $city),
            ])->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'urls' => [
                'index' => route('admin.cities.index'),
                'store' => route('admin.cities.store'),
            ],
        ]);
    }

    public function storeCity(Request $request): RedirectResponse|JsonResponse
    {
        $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'state_id' => ['required', 'uuid', 'exists:states,id'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $normalized = City::normalizeName($validated['name']);
        $exists = City::query()
            ->where('state_id', $validated['state_id'])
            ->where('name_normalized', $normalized)
            ->exists();
        if ($exists) {
            $message = 'A city with this name already exists in the selected State.';
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['name' => $message]);
            }

            return back()->withErrors(['name' => $message]);
        }

        $city = $this->withPlatformTenant(function () use ($validated, $normalized): City {
            $created = City::query()->create([
                'state_id' => $validated['state_id'],
                'name' => trim($validated['name']),
                'name_normalized' => $normalized,
                'is_active' => true,
            ]);

            $this->audit->write(
                AuditAction::Created,
                City::class,
                $created->id,
                remark: 'City created via admin UI',
                before: null,
                after: $created->toArray(),
            );

            return $created;
        });

        $city->load('state:id,name');

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'id' => $city->id,
                    'name' => $city->name,
                    'state_id' => $city->state_id,
                    'state_name' => $city->state?->name,
                ],
            ], 201);
        }

        return back()->with('success', 'City “'.$city->name.'” added to the State master.');
    }

    public function toggleCity(Request $request, City $city): RedirectResponse
    {
        $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
        ]);

        $this->withPlatformTenant(function () use ($city, $validated): void {
            $before = $city->toArray();
            $city->forceFill([
                'is_active' => ! $city->is_active,
            ])->save();

            $this->audit->write(
                AuditAction::Updated,
                City::class,
                $city->id,
                remark: $validated['remark'],
                before: $before,
                after: $city->fresh()?->toArray(),
            );
        });

        return back()->with(
            'success',
            $city->is_active
                ? 'City reactivated. Historical references remain intact.'
                : 'City deactivated. Historical references remain intact.',
        );
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

    private function assertPlatformOperator(Request $request): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        if ($actor === null || ! $actor->isInternal() || ! $actor->isActive()) {
            abort(403, 'Internal platform operator required.');
        }

        return $actor;
    }
}
