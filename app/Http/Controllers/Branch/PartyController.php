<?php

namespace App\Http\Controllers\Branch;

use App\Domains\Master\Party\Enums\PartyRoleType;
use App\Domains\Master\Party\Exceptions\PartyDomainException;
use App\Domains\Master\Party\PartyService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Backend Party master endpoints (product UI gated on Phase 3 design approval).
 */
class PartyController extends Controller
{
    public function index(
        Request $request,
        Company $company,
        Branch $branch,
        PartyService $parties,
    ): JsonResponse {
        $this->assertBranch($company, $branch);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $page = $parties->search(
                actor: $actor,
                company: $company,
                branch: $branch,
                query: $request->string('q')->toString() ?: null,
                role: $request->string('role')->toString() ?: null,
                perPage: min(100, max(1, (int) $request->input('per_page', 15))),
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json($page);
    }

    public function store(
        Request $request,
        Company $company,
        Branch $branch,
        PartyService $parties,
    ): JsonResponse {
        $this->assertBranch($company, $branch);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'scope' => ['required', Rule::in(['shared', 'branch'])],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'],
            'is_gst_applicable' => ['sometimes', 'boolean'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.role' => ['required', Rule::enum(PartyRoleType::class)],
            'roles.*.attributes' => ['nullable', 'array'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $scopeBranch = $validated['scope'] === 'branch' ? $branch : null;

        try {
            $party = $parties->create(
                actor: $actor,
                company: $company,
                branch: $scopeBranch,
                attributes: $validated,
                roles: $validated['roles'],
                remark: $validated['remark'] ?? 'Create party',
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['data' => $party], 201);
    }

    public function update(
        Request $request,
        Company $company,
        Branch $branch,
        Party $party,
        PartyService $parties,
    ): JsonResponse {
        $this->assertBranch($company, $branch);
        $this->assertParty($company, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'],
            'is_gst_applicable' => ['sometimes', 'boolean'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $updated = $parties->update($actor, $party, $validated, $validated['remark']);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['data' => $updated]);
    }

    public function assignRole(
        Request $request,
        Company $company,
        Branch $branch,
        Party $party,
        PartyService $parties,
    ): JsonResponse {
        $this->assertBranch($company, $branch);
        $this->assertParty($company, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'role' => ['required', Rule::enum(PartyRoleType::class)],
            'attributes' => ['nullable', 'array'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $role = $parties->assignRole(
                $actor,
                $party,
                PartyRoleType::from($validated['role']),
                $validated['attributes'] ?? [],
                $validated['remark'],
            );
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['data' => $role], 201);
    }

    public function removeRole(
        Request $request,
        Company $company,
        Branch $branch,
        Party $party,
        PartyRole $partyRole,
        PartyService $parties,
    ): JsonResponse {
        $this->assertBranch($company, $branch);
        $this->assertParty($company, $party);

        if ($partyRole->party_id !== $party->id || $partyRole->company_id !== $company->id) {
            abort(404);
        }

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $role = $parties->removeRole($actor, $partyRole, $validated['remark']);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json(['data' => $role]);
    }

    public function destroy(
        Request $request,
        Company $company,
        Branch $branch,
        Party $party,
        PartyService $parties,
    ): JsonResponse {
        $this->assertBranch($company, $branch);
        $this->assertParty($company, $party);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $parties->softDelete($actor, $party, $validated['remark']);
        } catch (PartyDomainException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json(['status' => 'deleted']);
    }

    private function assertBranch(Company $company, Branch $branch): void
    {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }
    }

    private function assertParty(Company $company, Party $party): void
    {
        if ($party->company_id !== $company->id) {
            abort(404);
        }
    }
}
