<?php

namespace App\Http\Controllers\Owner;

use App\Domains\Company\OwnerGlobalPanelService;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global Owner subscription company list (PO-OWNER-GLOBAL-MANAGEMENT).
 */
class OwnerSubscriptionsController extends Controller
{
    public function __construct(
        private readonly OwnerGlobalPanelService $panel,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->panel->assertOwnsAtLeastOneCompany($user);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        $paginator = $this->panel->paginateOwnedSubscriptions($user, $search, $status);

        return Inertia::render('owner/subscriptions/index', [
            'filters' => [
                'q' => $search,
                'status' => $status,
            ],
            'companies' => [
                'data' => $paginator->getCollection()->map(
                    fn (Company $company): array => $this->panel->presentSubscriptionRow($company),
                )->values(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'links' => [
                'index' => route('owner.subscriptions.index'),
                'companies' => route('owner.companies.index'),
            ],
        ]);
    }
}
