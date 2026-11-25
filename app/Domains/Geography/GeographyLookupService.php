<?php

namespace App\Domains\Geography;

use App\Models\City;
use App\Models\State;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Central State/City lookup and validation for Master-based address forms.
 * Names are derived exclusively from selected Master records — no client text fallback.
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
     * Resolve Master geographic IDs into authoritative ID + denormalized name pairs.
     * Client-supplied names are ignored; CRM Lead free-text is handled separately.
     *
     * @return array{state_id: string|null, city_id: string|null, state: string|null, city: string|null}
     */
    public function resolveAddressFields(?string $stateId, ?string $cityId): array
    {
        $state = null;
        $city = null;

        if (filled($stateId)) {
            if (! $this->looksLikeUuid((string) $stateId) || str_starts_with((string) $stateId, 'custom:')) {
                throw ValidationException::withMessages([
                    'state_id' => 'Select a valid State from the master list.',
                ]);
            }

            $state = State::query()->whereKey($stateId)->where('is_active', true)->first();
            if ($state === null) {
                throw ValidationException::withMessages([
                    'state_id' => 'Select a valid State from the master list.',
                ]);
            }
        }

        if (filled($cityId)) {
            if (! $this->looksLikeUuid((string) $cityId) || str_starts_with((string) $cityId, 'custom:')) {
                throw ValidationException::withMessages([
                    'city_id' => 'Select a valid City from the master list.',
                ]);
            }

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
            'state' => $state?->name,
            'city' => $city?->name,
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

    private function looksLikeUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
    }
}
