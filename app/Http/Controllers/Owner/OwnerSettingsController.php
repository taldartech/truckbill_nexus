<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Domains\Company\CompanyWorkspaceService;
use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Owner company profile / settings hub (P2-WS-8 follow-on).
 *
 * Editable fields are limited to schema-backed company name. Legal/tax identifiers
 * are not inventable without approved schema and policy.
 */
class OwnerSettingsController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
        private readonly CompanyWorkspaceService $workspace,
    ) {}

    public function show(Request $request, Company $company): Response
    {
        ['user' => $user, 'scope' => $scope] = $this->authorizeOwnerPanel($request, $company, $this->access);

        $owner = User::query()->find($company->owner_user_id);

        return Inertia::render('owner/settings', [
            'company' => array_merge($this->companyPayload($company), [
                'owner' => $owner === null ? null : [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                ],
                'updated_at' => $company->updated_at?->toIso8601String(),
            ]),
            'ownerPanelScope' => $scope,
            'canEditProfile' => $scope === CompanyAccessService::OWNER_PANEL_FULL,
            'deferredSections' => [
                [
                    'key' => 'visibility',
                    'title' => 'Data visibility',
                    'message' => 'Company-level visibility configuration is reserved in Owner Panel IA and is not implemented in this slice.',
                ],
                [
                    'key' => 'numbering',
                    'title' => 'Numbering',
                    'message' => 'Document numbering configuration is reserved and not implemented in this slice.',
                ],
                [
                    'key' => 'documents',
                    'title' => 'Documents, logo & signature',
                    'message' => 'Company document assets are reserved and not implemented in this slice.',
                ],
            ],
            'links' => [
                'dashboard' => route('owner.dashboard', ['company' => $company]),
                'update' => route('owner.settings.update', ['company' => $company]),
            ],
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access, requireFullScope: true);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->workspace->updateCompany($company, $validated['name'], $validated['remark']);
        } catch (CompanyDomainException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('owner.settings', ['company' => $company])
            ->with('success', 'Company profile updated.');
    }
}
