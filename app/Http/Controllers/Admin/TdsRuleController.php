<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Tax\Enums\PanStatus;
use App\Domains\Tax\Exceptions\TaxDomainException;
use App\Domains\Tax\Exceptions\TdsNoMatchException;
use App\Domains\Tax\TdsConditionPresenter;
use App\Domains\Tax\TdsRuleEvaluator;
use App\Domains\Tax\TdsRuleService;
use App\Http\Controllers\Controller;
use App\Models\TdsRule;
use App\Models\User;
use App\Support\Database\CaseInsensitiveLike;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        private readonly TdsRuleEvaluator $evaluator = new TdsRuleEvaluator,
        private readonly TdsConditionPresenter $presenter = new TdsConditionPresenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->assertPlatformOperator($request);

        $search = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());

        $query = TdsRule::query()->orderBy('priority')->orderBy('code');

        if ($search !== '') {
            CaseInsensitiveLike::whereAnyContains($query, ['name', 'code', 'description'], $search);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $rules = $query->limit(200)->get();

        $maxPriority = (int) TdsRule::query()->max('priority');

        return Inertia::render('admin/tds-rules', [
            'compliance_note' => 'Central TDS rule definitions for platform configuration. Production statutory validation remains Pending (TB-TAX). Editing a rule does not recalculate finalized financial snapshots.',
            'rules' => $rules->map(fn (TdsRule $rule): array => [
                'id' => $rule->id,
                'code' => $rule->code,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'condition_logic' => $rule->condition_logic,
                'conditions' => $rule->conditions,
                'conditions_summary' => $this->presenter->summarize($rule->conditions, (string) $rule->condition_logic),
                'rate_percent' => (string) $rule->rate_percent,
                'description' => $rule->description,
                'update_url' => route('admin.tds-rules.update', $rule),
            ])->values()->all(),
            'store_url' => route('admin.tds-rules.store'),
            'evaluate_url' => route('admin.tds-rules.evaluate'),
            'condition_fields' => TdsRuleService::CONDITION_FIELDS,
            'default_priority' => $maxPriority > 0 ? $maxPriority + 10 : 100,
            'preview_result' => $request->session()->get('tds_preview'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'condition_logic' => ['required', 'in:AND,OR'],
            'conditions_json' => ['required', 'string'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $conditions = $this->decodeConditions($validated['conditions_json']);
        if ($conditions === null) {
            return back()->withErrors(['conditions_json' => 'Conditions must be a valid list.']);
        }

        $code = strtoupper(trim((string) ($validated['code'] ?? '')));
        if ($code === '') {
            $code = Str::upper(Str::slug($validated['name'], '_'));
            $code = substr($code !== '' ? $code : 'TDS_RULE', 0, 64);
            if (TdsRule::query()->where('code', $code)->exists()) {
                $code = substr($code, 0, 54).'_'.Str::upper(Str::random(6));
            }
        }

        $priority = (int) ($validated['priority'] ?? 0);
        if ($priority < 1) {
            $priority = ((int) TdsRule::query()->max('priority')) + 10;
            if ($priority < 1) {
                $priority = 100;
            }
        }

        $shadowWarning = $this->shadowedByHigherPriority($priority, $conditions, $validated['condition_logic']);

        try {
            $this->tds->create($actor, [
                'code' => $code,
                'name' => $validated['name'],
                'priority' => $priority,
                'condition_logic' => $validated['condition_logic'],
                'conditions' => $conditions,
                'rate_percent' => $validated['rate_percent'],
                'description' => $validated['description'] ?? $this->presenter->previewSentence(
                    $conditions,
                    $validated['condition_logic'],
                    (string) $validated['rate_percent'],
                    $validated['name'],
                ),
                'is_active' => $validated['is_active'] ?? true,
            ], $validated['remark'] ?? 'Create TDS rule via admin UI');
        } catch (TaxDomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        } catch (ValidationException $e) {
            throw $e;
        }

        $message = 'TDS rule created.';
        if ($shadowWarning !== null) {
            $message .= ' Warning: '.$shadowWarning;
        }

        return back()->with('success', $message);
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
            $conditions = $this->decodeConditions($validated['conditions_json']);
            if ($conditions === null) {
                return back()->withErrors(['conditions_json' => 'Conditions must be a valid list.']);
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

        return back()->with('success', 'TDS rule updated. Historical financial snapshots are unchanged.');
    }

    public function evaluate(Request $request): RedirectResponse
    {
        $this->assertPlatformOperator($request);

        $validated = $request->validate([
            'pan_status' => ['nullable', Rule::enum(PanStatus::class)],
            'declaration_valid' => ['nullable', 'string', 'in:yes,no,'],
            'goods_vehicle_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'pan_fourth_char' => ['nullable', 'string', 'max:1'],
            'entity_type' => ['nullable', 'string', 'max:32'],
        ]);

        $context = [];
        if (filled($validated['pan_status'] ?? null)) {
            $context['pan_status'] = strtolower((string) $validated['pan_status']);
        }
        if (($validated['declaration_valid'] ?? '') === 'yes') {
            $context['declaration_valid'] = true;
        } elseif (($validated['declaration_valid'] ?? '') === 'no') {
            $context['declaration_valid'] = false;
        }
        if (array_key_exists('goods_vehicle_count', $validated) && $validated['goods_vehicle_count'] !== null) {
            $context['goods_vehicle_count'] = (int) $validated['goods_vehicle_count'];
        }
        if (filled($validated['pan_fourth_char'] ?? null)) {
            $context['pan_fourth_char'] = strtoupper((string) $validated['pan_fourth_char']);
        }
        if (filled($validated['entity_type'] ?? null)) {
            $context['entity_type'] = strtolower((string) $validated['entity_type']);
        }

        try {
            $result = $this->evaluator->evaluate($context);
            /** @var TdsRule $matched */
            $matched = $result['rule'];

            return back()->with('tds_preview', [
                'rule_name' => $matched->name,
                'rate_percent' => (string) $result['rate_percent'],
                'explanation' => $this->presenter->previewSentence(
                    $matched->conditions,
                    (string) $matched->condition_logic,
                    (string) $result['rate_percent'],
                    $matched->name,
                ),
                'matched' => true,
            ]);
        } catch (TdsNoMatchException $e) {
            return back()->with('tds_preview', [
                'rule_name' => null,
                'rate_percent' => null,
                'explanation' => $e->getMessage(),
                'matched' => false,
            ])->withErrors(['evaluate' => $e->getMessage()]);
        }
    }

    /**
     * @return list<array{field: string, operator: string, value: mixed}>|null
     */
    private function decodeConditions(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $normalized = [];
        foreach ($decoded as $condition) {
            if (! is_array($condition) || ! isset($condition['field'], $condition['operator'])) {
                return null;
            }
            $operator = strtolower((string) $condition['operator']);
            if ($operator === 'ne') {
                $operator = 'neq';
            }
            $normalized[] = [
                'field' => (string) $condition['field'],
                'operator' => $operator,
                'value' => $condition['value'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Warn when a higher-priority active rule may make a new rule unreachable for overlapping contexts.
     *
     * @param  list<array{field: string, operator: string, value: mixed}>  $conditions
     */
    private function shadowedByHigherPriority(int $priority, array $conditions, string $logic): ?string
    {
        $higher = TdsRule::query()
            ->where('is_active', true)
            ->where('priority', '<', $priority)
            ->orderBy('priority')
            ->get(['name', 'priority', 'condition_logic', 'conditions']);

        if ($higher->isEmpty()) {
            return null;
        }

        $names = $higher->take(3)->map(fn (TdsRule $rule): string => $rule->name.' (priority '.$rule->priority.')')->implode(', ');

        return 'Higher-priority active rule(s) evaluate first: '.$names.'. Confirm this new rule is still reachable.';
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
