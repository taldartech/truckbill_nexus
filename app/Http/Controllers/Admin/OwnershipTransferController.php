<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Company\Exceptions\CompanyDomainException;
use App\Domains\Company\OwnershipTransferService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Internal Super Admin / Admin ownership transfer (P2-WS-7).
 *
 * Company and target user are resolved from the route / validated id —
 * never from client-supplied company_id / owner_id tenant fields.
 * Product UI remains gated by P2-WS-8.
 */
class OwnershipTransferController extends Controller
{
    public function store(
        Request $request,
        Company $company,
        OwnershipTransferService $transfers,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);

        $newOwner = User::query()->whereKey($validated['target_user_id'])->first();

        if (! $newOwner instanceof User) {
            throw ValidationException::withMessages([
                'target_user_id' => 'The selected target user is invalid.',
            ]);
        }

        try {
            $transfers->transfer($actor, $company, $newOwner, $validated['remark']);
        } catch (CompanyDomainException|\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                $e instanceof \InvalidArgumentException ? 'remark' : 'target_user_id' => $e->getMessage(),
            ]);
        }

        return back();
    }
}
