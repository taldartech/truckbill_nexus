<?php

namespace App\Domains\Geography\Concerns;

use App\Domains\Geography\GeographyLookupService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Merge validated State/City Master IDs into address attributes for persistence.
 * Client-supplied State/City text is ignored — names come from Masters only.
 */
trait ResolvesAddressGeography
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function withResolvedGeography(array $attributes): array
    {
        $geography = new GeographyLookupService;
        $stateId = filled($attributes['state_id'] ?? null) ? (string) $attributes['state_id'] : null;
        $cityId = filled($attributes['city_id'] ?? null) ? (string) $attributes['city_id'] : null;

        // Accept legacy/test payloads that still send State/City names.
        if ($stateId === null && filled($attributes['state'] ?? null)) {
            $stateId = $geography->findActiveStateIdByName((string) $attributes['state']);
        }
        if ($cityId === null && $stateId !== null && filled($attributes['city'] ?? null)) {
            $cityId = $geography->findActiveCityIdByName($stateId, (string) $attributes['city']);
        }

        if ($stateId === null && $cityId === null
            && ! array_key_exists('state_id', $attributes)
            && ! array_key_exists('city_id', $attributes)
            && ! filled($attributes['state'] ?? null)
            && ! filled($attributes['city'] ?? null)) {
            return $attributes;
        }

        $resolved = $geography->resolveAddressFields($stateId, $cityId);

        unset($attributes['state'], $attributes['city']);

        return array_merge($attributes, $resolved);
    }

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    protected function geographyValidationRules(bool $required = false): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'state_id' => [
                $presence,
                'uuid',
                Rule::exists('states', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'city_id' => [
                $presence,
                'uuid',
                Rule::exists('cities', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            // Optional name fields for legacy/test clients; resolved to Masters in withResolvedGeography.
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
        ];
    }
}
