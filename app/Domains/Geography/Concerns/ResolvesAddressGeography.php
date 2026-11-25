<?php

namespace App\Domains\Geography\Concerns;

use App\Domains\Geography\GeographyLookupService;

/**
 * Merge validated State/City master IDs into address attributes for persistence.
 */
trait ResolvesAddressGeography
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function withResolvedGeography(array $attributes): array
    {
        if (! array_key_exists('state_id', $attributes)
            && ! array_key_exists('city_id', $attributes)
            && ! array_key_exists('state', $attributes)
            && ! array_key_exists('city', $attributes)) {
            return $attributes;
        }

        $resolved = (new GeographyLookupService)->resolveAddressFields(
            filled($attributes['state_id'] ?? null) ? (string) $attributes['state_id'] : null,
            filled($attributes['city_id'] ?? null) ? (string) $attributes['city_id'] : null,
            isset($attributes['state']) ? (string) $attributes['state'] : null,
            isset($attributes['city']) ? (string) $attributes['city'] : null,
        );

        return array_merge($attributes, $resolved);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function geographyValidationRules(bool $required = false): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'state_id' => [$presence, 'uuid', 'exists:states,id'],
            'city_id' => [$presence, 'uuid', 'exists:cities,id'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ];
    }
}
