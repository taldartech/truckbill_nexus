<?php

namespace App\Domains\Geography;

use App\Models\City;
use App\Models\State;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Central State/City lookup and validation for address forms.
 */
final class GeographyLookupService
{
    /**
     * @return list<array{id: string, name: string, code: string, gst_code: string}>
     */
    public function listActiveStates(?string $search = null, int $limit = 50): array
    {
        $query = State::query()
            ->where('country_code', 'IN')
            ->where('is_active', true)
            ->orderBy('name');

        $search = trim((string) $search);
        if ($search !== '') {
            CaseInsensitiveLike::whereAnyContains($query, ['name', 'code'], $search);
        }

        /** @var list<array{id: string, name: string, code: string, gst_code: string}> $states */
        $states = array_values($query->limit($limit)->get()->map(fn (State $state): array => [
            'id' => $state->id,
            'name' => $state->name,
            'code' => $state->code,
            'gst_code' => $state->gst_code,
        ])->all());

        return $states;
    }

    /**
     * @return list<array{id: string, name: string, state_id: string}>
     */
    public function searchCities(string $stateId, ?string $search = null, int $limit = 40): array
    {
        $state = State::query()->whereKey($stateId)->where('is_active', true)->first();
        if ($state === null) {
            return [];
        }

        $query = City::query()
            ->where('state_id', $state->id)
            ->where('is_active', true)
            ->orderBy('name');

        $search = trim((string) $search);
        if ($search !== '') {
            CaseInsensitiveLike::whereAnyContains($query, ['name'], $search);
        }

        /** @var list<array{id: string, name: string, state_id: string}> $cities */
        $cities = array_values($query->limit($limit)->get()->map(fn (City $city): array => [
            'id' => $city->id,
            'name' => $city->name,
            'state_id' => $city->state_id,
        ])->all());

        return $cities;
    }

    /**
     * @return array{state_id: string|null, city_id: string|null, state: string|null, city: string|null}
     */
    public function resolveAddressFields(?string $stateId, ?string $cityId, ?string $fallbackState = null, ?string $fallbackCity = null): array
    {
        $state = null;
        $city = null;

        if (filled($stateId)) {
            $state = State::query()->whereKey($stateId)->where('is_active', true)->first();
            if ($state === null) {
                throw ValidationException::withMessages([
                    'state_id' => 'Select a valid State from the master list.',
                ]);
            }
        }

        if (filled($cityId)) {
            $city = City::query()->whereKey($cityId)->where('is_active', true)->first();
            if ($city === null) {
                throw ValidationException::withMessages([
                    'city_id' => 'Select a valid City from the master list.',
                ]);
            }

            if ($state === null || $city->state_id !== $state->id) {
                throw ValidationException::withMessages([
                    'city_id' => 'City must belong to the selected State.',
                ]);
            }
        }

        return [
            'state_id' => $state?->id,
            'city_id' => $city?->id,
            'state' => ($state !== null ? $state->name : null)
                ?? (filled($fallbackState) ? trim((string) $fallbackState) : null),
            'city' => ($city !== null ? $city->name : null)
                ?? (filled($fallbackCity) ? trim((string) $fallbackCity) : null),
        ];
    }

    /**
     * @return Collection<int, State>
     */
    public function allActiveStates(): Collection
    {
        return State::query()
            ->where('country_code', 'IN')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
