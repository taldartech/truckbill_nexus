<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Geography\GeographyLookupService;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Team Platform → Locations (State/City master). Minimal maintenance only.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly GeographyLookupService $geography = new GeographyLookupService,
    ) {}

    public function index(Request $request): Response
    {
        $this->assertPlatformOperator($request);

        $stateId = $request->string('state_id')->toString();
        $citySearch = trim($request->string('q')->toString());

        $states = $this->geography->allActiveStates()->map(fn (State $state): array => [
            'id' => $state->id,
            'name' => $state->name,
            'code' => $state->code,
            'gst_code' => $state->gst_code,
            'is_active' => $state->is_active,
            'cities_count' => $state->cities()->count(),
        ])->values()->all();

        $cities = [];
        if ($stateId !== '') {
            $query = City::query()
                ->where('state_id', $stateId)
                ->orderBy('name');
            if ($citySearch !== '') {
                CaseInsensitiveLike::whereAnyContains($query, ['name'], $citySearch);
            }
            $cities = $query->limit(200)->get()->map(fn (City $city): array => [
                'id' => $city->id,
                'name' => $city->name,
                'is_active' => $city->is_active,
                'state_id' => $city->state_id,
            ])->values()->all();
        }

        return Inertia::render('admin/locations', [
            'filters' => [
                'state_id' => $stateId,
                'q' => $citySearch,
            ],
            'states' => $states,
            'cities' => $cities,
            'urls' => [
                'index' => route('admin.locations.index'),
                'store_city' => route('admin.locations.cities.store'),
                'toggle_city' => route('admin.locations.cities.toggle', ['city' => 'CITY_ID']),
            ],
            'note' => 'Platform Locations master. State and GST state codes are centrally governed. Ordinary Owners cannot alter these records.',
        ]);
    }

    public function storeCity(Request $request): RedirectResponse
    {
        $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'state_id' => ['required', 'uuid', 'exists:states,id'],
            'name' => ['required', 'string', 'max:120'],
            'remark' => ['required', 'string', 'max:500'],
        ]);

        $normalized = City::normalizeName($validated['name']);
        $exists = City::query()
            ->where('state_id', $validated['state_id'])
            ->where('name_normalized', $normalized)
            ->exists();
        if ($exists) {
            return back()->withErrors(['name' => 'A city with this name already exists in the selected State.']);
        }

        City::query()->create([
            'state_id' => $validated['state_id'],
            'name' => trim($validated['name']),
            'name_normalized' => $normalized,
            'is_active' => true,
        ]);

        return back()->with('success', 'City added to the State master.');
    }

    public function toggleCity(Request $request, City $city): RedirectResponse
    {
        $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:500'],
        ]);

        $city->forceFill([
            'is_active' => ! $city->is_active,
        ])->save();

        return back()->with(
            'success',
            $city->is_active
                ? 'City reactivated. Historical references remain intact.'
                : 'City deactivated. Historical references remain intact.',
        );
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
