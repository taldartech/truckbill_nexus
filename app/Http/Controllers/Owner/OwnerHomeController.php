<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\OwnerGlobalPanelService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Workspace\WorkspaceSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global Owner Panel dashboard (PO-OWNER-GLOBAL-MANAGEMENT premium shell).
 */
class OwnerHomeController extends Controller
{
    public function __construct(
        private readonly OwnerGlobalPanelService $panel,
    ) {}

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->panel->assertCustomerOwnerActor($user);

        // Global Owner management is list-driven — clear active company/branch context.
        WorkspaceSession::clear($request);

        $payload = $this->panel->dashboardPayload($user);

        return Inertia::render('owner/home', [
            ...$payload,
            'links' => [
                'companies' => route('owner.companies.index'),
                'branches' => route('owner.branches.all'),
                'users' => route('owner.users.all'),
                'subscriptions' => route('owner.subscriptions.index'),
                'audit' => route('owner.audit.all'),
                'create_company' => route('owner.companies.create'),
            ],
        ]);
    }
}
