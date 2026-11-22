<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\TdsRuleService;
use App\Http\Controllers\Controller;
use App\Models\TdsRule;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin / platform TDS rule administration (IN-T-02).
 * Not a production statutory-compliance claim — TB-TAX validation remains Pending.
 */
class TdsRuleController extends Controller
{
    public function __construct(
        private readonly TdsRuleService $tds = new TdsRuleService,
    ) {}

    public function index(Request $request): Response
    {
        $this->assertPlatformOperator($request);

        $rules = TdsRule::query()
            ->orderBy('priority')
            ->orderBy('code')
            ->limit(200)
            ->get();

        return Inertia::render('admin/tds-rules', [
            'compliance_note' => 'Central TDS rule definitions for platform configuration. Production statutory validation remains Pending (TB-TAX).',
            'rules' => $rules->map(fn (TdsRule $rule): array => [
                'id' => $rule->id,
                'code' => $rule->code,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'condition_logic' => $rule->condition_logic,
                'conditions' => $rule->conditions,
                'rate_percent' => (string) $rule->rate_percent,
                'description' => $rule->description,
                'update_url' => route('admin.tds-rules.update', $rule),
            ])->values()->all(),
            'store_url' => route('admin.tds-rules.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'priority' => ['required', 'integer', 'min:1', 'max:10000'],
            'condition_logic' => ['required', 'in:AND,OR'],
            'conditions_json' => ['required', 'string'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $conditions = json_decode($validated['conditions_json'], true);
        if (! is_array($conditions)) {
            return back()->withErrors(['conditions_json' => 'Conditions must be valid JSON array.']);
        }

        try {
            $this->tds->create($actor, [
                'code' => $validated['code'],
                'name' => $validated['name'],
                'priority' => $validated['priority'],
                'condition_logic' => $validated['condition_logic'],
                'conditions' => $conditions,
                'rate_percent' => $validated['rate_percent'],
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ], $validated['remark'] ?? 'Create TDS rule via admin UI');
        } catch (TaxDomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'TDS rule created.');
    }

    public function update(Request $request, TdsRule $rule): RedirectResponse
    {
        $actor = $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'condition_logic' => ['sometimes', 'in:AND,OR'],
            'conditions_json' => ['sometimes', 'string'],
            'rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'remark' => ['required', 'string', 'max:255'],
        ]);

        /** @var array<string, mixed> $attributes */
        $attributes = [];
        foreach (['name', 'priority', 'is_active', 'condition_logic', 'rate_percent', 'description'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }
        if (isset($validated['conditions_json'])) {
            $conditions = json_decode($validated['conditions_json'], true);
            if (! is_array($conditions)) {
                return back()->withErrors(['conditions_json' => 'Conditions must be valid JSON array.']);
            }
            $attributes['conditions'] = $conditions;
        }

        try {
            $this->tds->update($actor, $rule, $attributes, $validated['remark']);
        } catch (TaxDomainException $e) {
            return back()->withErrors(['remark' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'TDS rule updated.');
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
