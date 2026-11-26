<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\CompanyAccessService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Owner\Concerns\AuthorizesOwnerPanel;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Read-only company audit viewer for Owners (append-only AuditEntry store).
 */
class OwnerAuditController extends Controller
{
    use AuthorizesOwnerPanel;

    public function __construct(
        private readonly CompanyAccessService $access,
    ) {}

    public function __invoke(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeOwnerPanel($request, $company, $this->access);

        return redirect()->route('owner.audit.all', ['company' => $company->id]);
    }
}
