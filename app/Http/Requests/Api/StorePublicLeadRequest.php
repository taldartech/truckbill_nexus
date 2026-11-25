<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'product_interested_in' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'estimated_trucks' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'landing_url' => ['nullable', 'string', 'max:2000'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'ip_address' => ['nullable', 'ip'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            // Privileged fields must never be accepted from the public website.
            'status' => ['prohibited'],
            'assigned_to_user_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'converted_company_id' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trimKeys = [
            'name', 'company_name', 'mobile', 'email', 'city', 'state',
            'product_interested_in', 'source', 'remarks',
            'utm_source', 'utm_medium', 'utm_campaign', 'landing_url', 'referrer',
            'idempotency_key',
        ];

        $payload = [];
        foreach ($trimKeys as $key) {
            if ($this->exists($key) && is_string($this->input($key))) {
                $payload[$key] = trim($this->input($key));
            }
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
