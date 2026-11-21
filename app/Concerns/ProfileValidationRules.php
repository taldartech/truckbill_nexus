<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate self-service user profiles.
     *
     * Name only — email changes require authorized administration (DEC-009).
     *
     * @return array<string, array<int, Unique|ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(): array
    {
        return [
            'name' => $this->nameRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
