<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditWriter;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\TenantContextHolder;
use Database\Seeders\IndiaGeographyData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Authenticated quick-create for missing geography (Owner forms + Internal).
 * Does not grant master administration (edit/deactivate/GST code changes).
 */
class GeographyQuickCreateController extends Controller
{
    public function __construct(
        private readonly AuditWriter $audit = new AuditWriter,
    ) {}

    public function storeState(Request $request): JsonResponse
    {
        $actor = $this->assertActiveUser($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:16'],
            'gst_code' => ['nullable', 'string', 'max:2'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);

        $catalogue = $this->resolveCatalogueEntry(
            trim($validated['name']),
            isset($validated['code']) ? strtoupper(trim((string) $validated['code'])) : null,
            isset($validated['gst_code']) ? trim((string) $validated['gst_code']) : null,
        );

        if ($catalogue === null && ! $actor->isInternal()) {
            throw ValidationException::withMessages([
                'name' => 'State must match an approved Indian State or Union Territory. Contact TruckBill support if a catalogue entry is missing.',
            ]);
        }

        if ($catalogue !== null) {
            $name = $catalogue['name'];
            $code = $catalogue['code'];
            $gstCode = $catalogue['gst_code'];
        } else {
            // Internal Team freeform — still requires code + GST jurisdiction.
            $request->validate([
                'code' => ['required', 'string', 'max:16'],
                'gst_code' => ['required', 'string', 'max:2', 'regex:/^[0-9]{2}$/'],
            ]);
            $name = trim($validated['name']);
            $code = strtoupper(trim((string) $validated['code']));
            $gstCode = str_pad(trim((string) $validated['gst_code']), 2, '0', STR_PAD_LEFT);
        }

        $existing = State::query()
            ->where(function ($query) use ($code, $gstCode, $name): void {
                $query->where('code', $code)
                    ->orWhere('gst_code', $gstCode)
                    ->orWhereRaw('lower(name) = ?', [mb_strtolower($name)]);
            })
            ->first();

        if ($existing instanceof State) {
            if (! $existing->is_active) {
                throw ValidationException::withMessages([
                    'name' => 'This State exists but is deactivated. Ask an Internal operator to reactivate it.',
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'code' => $existing->code,
                    'gst_code' => $existing->gst_code,
                ],
            ]);
        }

        $state = $this->withPlatformTenant(function () use ($name, $code, $gstCode, $validated, $actor): State {
            $created = State::query()->create([
                'name' => $name,
                'code' => $code,
                'gst_code' => $gstCode,
                'country_code' => strtoupper(trim((string) ($validated['country_code'] ?? 'IN'))) ?: 'IN',
                'is_active' => true,
            ]);

            $this->audit->write(
                AuditAction::Created,
                State::class,
                $created->id,
                remark: 'State quick-created by user #'.$actor->id,
                before: null,
                after: $created->toArray(),
            );

            return $created;
        });

        return response()->json([
            'data' => [
                'id' => $state->id,
                'name' => $state->name,
                'code' => $state->code,
                'gst_code' => $state->gst_code,
            ],
        ], 201);
    }

    public function storeCity(Request $request): JsonResponse
    {
        $actor = $this->assertActiveUser($request);

        $validated = $request->validate([
            'state_id' => ['required', 'uuid', 'exists:states,id'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $state = State::query()->whereKey($validated['state_id'])->firstOrFail();
        if (! $state->is_active) {
            throw ValidationException::withMessages([
                'state_id' => 'Selected State is not active.',
            ]);
        }

        $normalized = City::normalizeName($validated['name']);
        $existing = City::query()
            ->where('state_id', $state->id)
            ->where('name_normalized', $normalized)
            ->first();

        if ($existing instanceof City) {
            if (! $existing->is_active) {
                throw ValidationException::withMessages([
                    'name' => 'This City exists but is deactivated. Ask an Internal operator to reactivate it.',
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'state_id' => $existing->state_id,
                    'state_name' => $state->name,
                ],
            ]);
        }

        $city = $this->withPlatformTenant(function () use ($validated, $normalized, $actor): City {
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
                remark: 'City quick-created by user #'.$actor->id,
                before: null,
                after: $created->toArray(),
            );

            return $created;
        });

        return response()->json([
            'data' => [
                'id' => $city->id,
                'name' => $city->name,
                'state_id' => $city->state_id,
                'state_name' => $state->name,
            ],
        ], 201);
    }

    private function assertActiveUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isActive()) {
            abort(403, 'Active authentication required.');
        }

        return $user;
    }

    /**
     * @return array{code: string, name: string, gst_code: string}|null
     */
    private function resolveCatalogueEntry(string $name, ?string $code, ?string $gstCode): ?array
    {
        foreach (IndiaGeographyData::STATES as $iso => $meta) {
            $entry = [
                'code' => (string) $iso,
                'name' => $meta['name'],
                'gst_code' => $meta['gst_code'],
            ];

            if ($code !== null && $code !== '' && strtoupper($code) === $entry['code']) {
                return $entry;
            }

            if ($gstCode !== null && $gstCode !== '' && str_pad($gstCode, 2, '0', STR_PAD_LEFT) === $entry['gst_code']) {
                return $entry;
            }

            if (mb_strtolower($name) === mb_strtolower($entry['name'])) {
                return $entry;
            }

            // Soft match: ignore punctuation/spacing differences.
            if ($this->normalizeLabel($name) === $this->normalizeLabel($entry['name'])) {
                return $entry;
            }
        }

        return null;
    }

    private function normalizeLabel(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/u', '', $normalized) ?? $normalized;

        return $normalized;
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
}
