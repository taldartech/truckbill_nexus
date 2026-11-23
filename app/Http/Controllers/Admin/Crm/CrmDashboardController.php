<?php

namespace App\Http\Controllers\Admin\Crm;

use App\Domains\Crm\CrmDashboardService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrmDashboardController extends Controller
{
    public function __construct(
        private readonly CrmDashboardService $dashboard = new CrmDashboardService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $actor = $this->assertInternal($request);

        return Inertia::render('admin/crm/dashboard', [
            'metrics' => $this->dashboard->metrics($actor),
            'urls' => [
                'leads' => route('admin.crm.leads.index'),
                'tickets' => route('admin.crm.tickets.index'),
                'create_lead' => route('admin.crm.leads.create'),
                'create_ticket' => route('admin.crm.tickets.create'),
            ],
        ]);
    }

    private function assertInternal(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isInternal() || ! $actor->isActive()) {
            abort(403, 'Internal CRM access required.');
        }

        return $actor;
    }
}
