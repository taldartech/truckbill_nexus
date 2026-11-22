<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Invitation\Exceptions\InvitationException;
use App\Domains\Invitation\InvitationService;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function store(Request $request, Company $company, InvitationService $invitations): RedirectResponse
    {
        return $this->createInvitation($request, $company, null, $invitations);
    }

    public function storeForBranch(
        Request $request,
        Company $company,
        Branch $branch,
        InvitationService $invitations,
    ): RedirectResponse {
        if ($branch->company_id !== $company->id) {
            abort(404);
        }

        return $this->createInvitation($request, $company, $branch, $invitations);
    }

    public function revoke(Request $request, Invitation $invitation, InvitationService $invitations): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $invitations->revoke($actor, $invitation, $validated['remark']);
        } catch (InvitationException $e) {
            throw ValidationException::withMessages(['remark' => $e->getMessage()]);
        }

        return back();
    }

    private function createInvitation(
        Request $request,
        Company $company,
        ?Branch $branch,
        InvitationService $invitations,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $invitations->create($actor, $company, $validated['email'], $branch);
        } catch (InvitationException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return back();
    }
}
