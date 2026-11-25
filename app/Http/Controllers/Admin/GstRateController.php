<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\GstRateService;
use App\Http\Controllers\Controller;
use App\Models\GstRate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin / platform GST rate administration (IN-T-01).
 * Not a production statutory-compliance claim — TB-TAX validation remains Pending.
 */
class GstRateController extends Controller
{
    public function __construct(
        private readonly GstRateService $gst = new GstRateService,
    ) {}

    public function index(Request $request): Response
    {
        $this->assertPlatformOperator($request);

        $rates = GstRate::query()
            ->orderBy('rate_percent')
            ->orderByDesc('version')
            ->limit(200)
            ->get();

        return Inertia::render('admin/gst-rates', [
            'compliance_note' => 'Central GST rate versions for platform configuration. Production statutory validation remains Pending (TB-TAX). Historical versions are preserved; creating a successor closes the prior open version.',
            'rates' => $rates->map(fn (GstRate $rate): array => [
                'id' => $rate->id,
                'label' => $rate->displayLabel(),
                'rate_percent' => (string) $rate->rate_percent,
                'version' => $rate->version,
                'is_active' => $rate->is_active,
                'effective_from' => $rate->effective_from->toDateString(),
                'effective_to' => $rate->effective_to !== null ? $rate->effective_to->toDateString() : null,
                'deactivate_url' => route('admin.gst-rates.deactivate', $rate),
            ])->values()->all(),
            'store_url' => route('admin.gst-rates.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->gst->createVersion($actor, [
                'rate_percent' => $validated['rate_percent'],
                'is_active' => $validated['is_active'] ?? true,
            ], $validated['remark'] ?? 'Create GST rate via admin UI');
        } catch (TaxDomainException $e) {
            return back()->withErrors(['rate_percent' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'GST rate created. Prior active version closed when applicable.');
    }

    public function deactivate(Request $request, GstRate $rate): RedirectResponse
    {
        $actor = $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->gst->deactivate($actor, $rate, $validated['remark']);
        } catch (TaxDomainException $e) {
            return back()->withErrors(['remark' => $e->getMessage()]);
        }

        return back()->with('success', 'GST rate deactivated. Historical snapshot retained.');
    }

    private function assertPlatformOperator(Request $request): User
    {
        /** @var User|null $actor */
        $actor = $request->user();
        if ($actor === null || ! $actor->isInternal() || ! $actor->isActive()) {
            abort(403, 'Internal platform operator required.');
        }

        return $actor;
    }
}
