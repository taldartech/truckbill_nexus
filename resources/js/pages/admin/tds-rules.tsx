import { Form, Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useMemo, useState } from 'react';
import {
    PortalErrorList,
    PortalFlashBanner,
} from '@/components/admin/portal-feedback';
import { AppIconAction } from '@/components/app-icon-action';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormSection } from '@/components/form-section';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type ConditionJson = {
    field: string;
    operator: string;
    value: string | number | boolean | string[];
};

type RuleRow = {
    id: string;
    code: string;
    name: string;
    priority: number;
    is_active: boolean;
    condition_logic: string;
    conditions: ConditionJson[] | null;
    rate_percent: string;
    description: string | null;
    conditions_summary?: string;
    update_url: string;
};

type TdsPreviewResult = {
    rule_name?: string;
    rate_percent?: string;
    explanation?: string;
};

type Props = {
    compliance_note: string;
    store_url: string;
    evaluate_url?: string;
    condition_fields?: string[];
    rules: RuleRow[];
};

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const OPERATORS = ['eq', 'neq', 'lte', 'gte', 'in', 'truthy'] as const;

const DEFAULT_FIELDS = [
    'pan_status',
    'declaration_valid',
    'goods_vehicle_count',
    'pan_fourth_char',
];

type PanStatus = 'any' | 'active' | 'inactive';
type DeclarationValid = 'any' | 'yes' | 'no';
type PanCharGroup = 'any' | 'individual' | 'entity';

type SimpleConditions = {
    panStatus: PanStatus;
    declarationValid: DeclarationValid;
    goodsVehicleMax: string;
    panCharGroup: PanCharGroup;
};

const defaultSimple: SimpleConditions = {
    panStatus: 'any',
    declarationValid: 'any',
    goodsVehicleMax: '',
    panCharGroup: 'any',
};

function slugFromName(name: string): string {
    const slug = name
        .trim()
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
    return slug.slice(0, 64);
}

function parseConditions(raw: unknown): ConditionJson[] {
    if (!Array.isArray(raw)) {
        return [];
    }
    return raw.filter(
        (item): item is ConditionJson =>
            typeof item === 'object' &&
            item !== null &&
            'field' in item &&
            'operator' in item &&
            'value' in item,
    );
}

function buildConditionsFromSimple(simple: SimpleConditions): ConditionJson[] {
    const conditions: ConditionJson[] = [];

    if (simple.panStatus !== 'any') {
        conditions.push({
            field: 'pan_status',
            operator: 'eq',
            value: simple.panStatus,
        });
    }
    if (simple.declarationValid !== 'any') {
        conditions.push({
            field: 'declaration_valid',
            operator: 'eq',
            value: simple.declarationValid === 'yes',
        });
    }
    const maxCount = simple.goodsVehicleMax.trim();
    if (maxCount !== '') {
        const parsed = Number(maxCount);
        if (!Number.isNaN(parsed)) {
            conditions.push({
                field: 'goods_vehicle_count',
                operator: 'lte',
                value: parsed,
            });
        }
    }
    if (simple.panCharGroup === 'individual') {
        conditions.push({
            field: 'pan_fourth_char',
            operator: 'in',
            value: ['P', 'H'],
        });
    } else if (simple.panCharGroup === 'entity') {
        conditions.push({
            field: 'pan_fourth_char',
            operator: 'in',
            value: ['C', 'F', 'A', 'B', 'J', 'L', 'T'],
        });
    }

    return conditions;
}

function coerceRowValue(value: string): string | number | boolean | string[] {
    const trimmed = value.trim();
    if (trimmed.includes(',')) {
        return trimmed
            .split(',')
            .map((part) => part.trim())
            .filter((part) => part !== '');
    }
    if (trimmed === 'true') {
        return true;
    }
    if (trimmed === 'false') {
        return false;
    }
    const asNumber = Number(trimmed);
    if (trimmed !== '' && !Number.isNaN(asNumber)) {
        return asNumber;
    }
    return trimmed;
}

function formatConditionValue(value: ConditionJson['value']): string {
    if (Array.isArray(value)) {
        return value.join(', ');
    }
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }
    return String(value);
}

function buildPreviewSentence(
    simple: SimpleConditions,
    logic: 'AND' | 'OR',
): string {
    const parts: string[] = [];

    if (simple.panStatus !== 'any') {
        parts.push(`PAN status is ${simple.panStatus}`);
    }
    if (simple.declarationValid !== 'any') {
        parts.push(
            `TDS declaration ${simple.declarationValid === 'yes' ? 'is received' : 'is not received'}`,
        );
    }
    if (simple.goodsVehicleMax.trim() !== '') {
        parts.push(`goods vehicle count ≤ ${simple.goodsVehicleMax.trim()}`);
    }
    if (simple.panCharGroup === 'individual') {
        parts.push('PAN 4th character is individual (P or H)');
    } else if (simple.panCharGroup === 'entity') {
        parts.push('PAN 4th character is entity (C, F, A, B, J, L, or T)');
    }

    if (parts.length === 0) {
        return 'Applies when no extra filters are set (matches all payees).';
    }

    const joiner = logic === 'OR' ? ' or ' : ' and ';
    return `TDS applies when ${parts.join(joiner)}.`;
}

function defaultPriority(rules: RuleRow[]): number {
    if (rules.length === 0) {
        return 100;
    }
    const max = Math.max(...rules.map((rule) => rule.priority));
    return max + 10;
}

type ConditionRowState = {
    id: string;
    field: string;
    operator: string;
    value: string;
};

function newConditionRow(field: string): ConditionRowState {
    return {
        id: crypto.randomUUID(),
        field,
        operator: 'eq',
        value: '',
    };
}

function conditionsToRows(conditions: ConditionJson[]): ConditionRowState[] {
    if (conditions.length === 0) {
        return [newConditionRow('pan_status')];
    }
    return conditions.map((row) => ({
        id: crypto.randomUUID(),
        field: row.field,
        operator: row.operator,
        value: Array.isArray(row.value)
            ? row.value.join(', ')
            : String(row.value),
    }));
}

function rowsToConditions(rows: ConditionRowState[]): ConditionJson[] {
    return rows
        .filter((row) => row.field.trim() !== '' && row.value.trim() !== '')
        .map((row) => ({
            field: row.field,
            operator: row.operator,
            value: coerceRowValue(row.value),
        }));
}

export default function AdminTdsRules({
    compliance_note,
    store_url,
    evaluate_url,
    condition_fields,
    rules,
}: Props) {
    const page = usePage<{
        flash?: {
            success?: string | null;
            tds_preview?: TdsPreviewResult | null;
        };
        tds_preview?: TdsPreviewResult;
        errors?: Record<string, string>;
    }>();

    const fields = condition_fields ?? DEFAULT_FIELDS;
    const defaultPri = useMemo(() => defaultPriority(rules), [rules]);

    const [ruleName, setRuleName] = useState('');
    const [ratePercent, setRatePercent] = useState('');
    const [isActive, setIsActive] = useState(true);
    const [conditionLogic, setConditionLogic] = useState<'AND' | 'OR'>('AND');
    const [priority, setPriority] = useState(String(defaultPri));
    const [simple, setSimple] = useState<SimpleConditions>(defaultSimple);
    const [useAdvanced, setUseAdvanced] = useState(false);
    const [advancedRows, setAdvancedRows] = useState<ConditionRowState[]>(
        () => [newConditionRow(fields[0] ?? 'pan_status')],
    );
    const [editingRuleId, setEditingRuleId] = useState<string | null>(null);

    const [testPanStatus, setTestPanStatus] = useState('');
    const [testDeclarationValid, setTestDeclarationValid] = useState('');
    const [testGoodsCount, setTestGoodsCount] = useState('');
    const [testPanFourth, setTestPanFourth] = useState('');

    const previewSentence = useMemo(
        () => buildPreviewSentence(simple, conditionLogic),
        [simple, conditionLogic],
    );

    const conditionsJson = useMemo(() => {
        const conditions = useAdvanced
            ? rowsToConditions(advancedRows)
            : buildConditionsFromSimple(simple);
        return JSON.stringify(conditions);
    }, [useAdvanced, advancedRows, simple]);

    const codeSlug = useMemo(() => slugFromName(ruleName), [ruleName]);

    const previewResult =
        page.props.tds_preview ?? page.props.flash?.tds_preview ?? null;

    const editingRule = useMemo(
        () => rules.find((rule) => rule.id === editingRuleId) ?? null,
        [rules, editingRuleId],
    );

    const columns = useMemo<DataTableColumn<RuleRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Rule name',
                priority: 0,
                cell: (row) => (
                    <>
                        <div className="font-medium">{row.name}</div>
                        <div className="font-mono text-xs text-muted-foreground">
                            {row.code}
                        </div>
                    </>
                ),
            },
            {
                id: 'conditions',
                header: 'Applicable conditions',
                priority: 2,
                className: 'max-w-md text-xs',
                cell: (row) => row.conditions_summary ?? row.description ?? '—',
            },
            {
                id: 'rate',
                header: 'TDS rate',
                priority: 2,
                cell: (row) => `${row.rate_percent}%`,
            },
            {
                id: 'priority',
                header: 'Priority',
                priority: 3,
                cell: (row) => row.priority,
            },
            {
                id: 'status',
                header: 'Status',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'deactivated'}
                    />
                ),
            },
        ],
        [],
    );

    const updateSimple = useCallback(
        <K extends keyof SimpleConditions>(
            key: K,
            value: SimpleConditions[K],
        ) => {
            setSimple((prev) => ({ ...prev, [key]: value }));
        },
        [],
    );

    const runPreviewTest = (event: React.FormEvent) => {
        event.preventDefault();
        if (!evaluate_url) {
            return;
        }
        router.post(
            evaluate_url,
            {
                pan_status: testPanStatus || undefined,
                declaration_valid: testDeclarationValid || undefined,
                goods_vehicle_count: testGoodsCount || undefined,
                pan_fourth_char: testPanFourth || undefined,
            },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="TDS rule master" />
            <PageShell title="TDS rules" description={compliance_note}>
                <PortalFlashBanner message={page.props.flash?.success} />
                <PortalErrorList
                    errors={page.props.errors}
                    keys={['code', 'name', 'remark', 'conditions_json']}
                />

                <Form action={store_url} method="post">
                    <input type="hidden" name="code" value={codeSlug} />
                    <input
                        type="hidden"
                        name="conditions_json"
                        value={conditionsJson}
                    />
                    <input
                        type="hidden"
                        name="description"
                        value={previewSentence}
                    />
                    <input
                        type="hidden"
                        name="condition_logic"
                        value={conditionLogic}
                    />
                    <input type="hidden" name="priority" value={priority} />

                    <FormSection title="Create rule" columns={2}>
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="name" required>
                                Rule name
                            </Label>
                            <Input
                                id="name"
                                name="name"
                                required
                                value={ruleName}
                                onChange={(event) =>
                                    setRuleName(event.target.value)
                                }
                            />
                        </div>
                        <div className="flex items-end">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    checked={isActive}
                                    onChange={(event) =>
                                        setIsActive(event.target.checked)
                                    }
                                    className="size-4 rounded border border-input"
                                />
                                Active
                            </label>
                        </div>

                        <SimpleConditionsHeading className="md:col-span-2" />

                        <FieldGroup label="PAN status">
                            <select
                                value={simple.panStatus}
                                onChange={(event) =>
                                    updateSimple(
                                        'panStatus',
                                        event.target.value as PanStatus,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="any">Any</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </FieldGroup>

                        <FieldGroup label="TDS declaration received">
                            <select
                                value={simple.declarationValid}
                                onChange={(event) =>
                                    updateSimple(
                                        'declarationValid',
                                        event.target.value as DeclarationValid,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="any">Any</option>
                                <option value="yes">Yes</option>
                                <option value="no">No</option>
                            </select>
                        </FieldGroup>

                        <FieldGroup label="Goods vehicle count max">
                            <Input
                                type="number"
                                min="0"
                                value={simple.goodsVehicleMax}
                                onChange={(event) =>
                                    updateSimple(
                                        'goodsVehicleMax',
                                        event.target.value,
                                    )
                                }
                                placeholder="Optional"
                            />
                        </FieldGroup>

                        <FieldGroup label="PAN 4th char group">
                            <select
                                value={simple.panCharGroup}
                                onChange={(event) =>
                                    updateSimple(
                                        'panCharGroup',
                                        event.target.value as PanCharGroup,
                                    )
                                }
                                className={selectClass}
                            >
                                <option value="any">Any</option>
                                <option value="individual">
                                    Individual (P, H)
                                </option>
                                <option value="entity">
                                    Entity (C, F, A, B, J, L, T)
                                </option>
                            </select>
                        </FieldGroup>

                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="rate_percent" required>
                                TDS rate (%)
                            </Label>
                            <Input
                                id="rate_percent"
                                name="rate_percent"
                                type="number"
                                step="0.001"
                                min="0"
                                max="100"
                                required
                                value={ratePercent}
                                onChange={(event) =>
                                    setRatePercent(event.target.value)
                                }
                            />
                        </div>

                        <div className="rounded-md border border-dashed border-border bg-muted/30 p-3 md:col-span-2">
                            <p className="text-xs font-medium text-muted-foreground">
                                Preview
                            </p>
                            <p className="mt-1 text-sm">{previewSentence}</p>
                        </div>

                        <details
                            className="md:col-span-2"
                            open={useAdvanced}
                            onToggle={(event) =>
                                setUseAdvanced(
                                    (event.currentTarget as HTMLDetailsElement)
                                        .open,
                                )
                            }
                        >
                            <summary className="cursor-pointer text-sm font-medium">
                                Advanced conditions
                            </summary>
                            <div className="mt-3 space-y-3">
                                <FieldGroup label="Condition logic">
                                    <select
                                        value={conditionLogic}
                                        onChange={(event) =>
                                            setConditionLogic(
                                                event.target.value as
                                                    | 'AND'
                                                    | 'OR',
                                            )
                                        }
                                        className={selectClass}
                                    >
                                        <option value="AND">AND</option>
                                        <option value="OR">OR</option>
                                    </select>
                                </FieldGroup>

                                {advancedRows.map((row) => (
                                    <div
                                        key={row.id}
                                        className="grid gap-2 md:grid-cols-12"
                                    >
                                        <div className="md:col-span-3">
                                            <select
                                                value={row.field}
                                                onChange={(event) =>
                                                    setAdvancedRows((prev) =>
                                                        prev.map((item) =>
                                                            item.id === row.id
                                                                ? {
                                                                      ...item,
                                                                      field: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                {fields.map((field) => (
                                                    <option
                                                        key={field}
                                                        value={field}
                                                    >
                                                        {field}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="md:col-span-2">
                                            <select
                                                value={row.operator}
                                                onChange={(event) =>
                                                    setAdvancedRows((prev) =>
                                                        prev.map((item) =>
                                                            item.id === row.id
                                                                ? {
                                                                      ...item,
                                                                      operator:
                                                                          event
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                {OPERATORS.map((op) => (
                                                    <option key={op} value={op}>
                                                        {op}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="md:col-span-6">
                                            <Input
                                                value={row.value}
                                                onChange={(event) =>
                                                    setAdvancedRows((prev) =>
                                                        prev.map((item) =>
                                                            item.id === row.id
                                                                ? {
                                                                      ...item,
                                                                      value: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : item,
                                                        ),
                                                    )
                                                }
                                                placeholder="Value (comma-separated for lists)"
                                            />
                                        </div>
                                        <div className="flex md:col-span-1 md:justify-end">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                aria-label="Remove condition row"
                                                disabled={
                                                    advancedRows.length <= 1
                                                }
                                                onClick={() =>
                                                    setAdvancedRows((prev) =>
                                                        prev.filter(
                                                            (item) =>
                                                                item.id !==
                                                                row.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}

                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="gap-1"
                                    onClick={() =>
                                        setAdvancedRows((prev) => [
                                            ...prev,
                                            newConditionRow(
                                                fields[0] ?? 'pan_status',
                                            ),
                                        ])
                                    }
                                >
                                    <Plus className="size-4" />
                                    Add condition
                                </Button>

                                <details className="text-xs">
                                    <summary className="cursor-pointer font-medium">
                                        Advanced settings
                                    </summary>
                                    <div className="mt-2 max-w-xs space-y-2">
                                        <Label htmlFor="priority">
                                            Priority
                                        </Label>
                                        <Input
                                            id="priority"
                                            type="number"
                                            min="1"
                                            value={priority}
                                            onChange={(event) =>
                                                setPriority(event.target.value)
                                            }
                                        />
                                    </div>
                                </details>
                            </div>
                        </details>

                        <div className="md:col-span-2">
                            <Button type="submit" variant="default">
                                Create TDS rule
                            </Button>
                        </div>
                    </FormSection>
                </Form>

                {evaluate_url ? (
                    <FormSection title="Rule testing preview" columns={2}>
                        <form onSubmit={runPreviewTest} className="contents">
                            <FieldGroup label="PAN status">
                                <select
                                    value={testPanStatus}
                                    onChange={(event) =>
                                        setTestPanStatus(event.target.value)
                                    }
                                    className={selectClass}
                                >
                                    <option value="">Any</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </FieldGroup>
                            <FieldGroup label="TDS declaration received">
                                <select
                                    value={testDeclarationValid}
                                    onChange={(event) =>
                                        setTestDeclarationValid(
                                            event.target.value,
                                        )
                                    }
                                    className={selectClass}
                                >
                                    <option value="">Any</option>
                                    <option value="yes">Yes</option>
                                    <option value="no">No</option>
                                </select>
                            </FieldGroup>
                            <FieldGroup label="Goods vehicle count">
                                <Input
                                    type="number"
                                    min="0"
                                    value={testGoodsCount}
                                    onChange={(event) =>
                                        setTestGoodsCount(event.target.value)
                                    }
                                />
                            </FieldGroup>
                            <FieldGroup label="PAN 4th character">
                                <Input
                                    value={testPanFourth}
                                    onChange={(event) =>
                                        setTestPanFourth(event.target.value)
                                    }
                                    maxLength={1}
                                    className="font-mono uppercase"
                                />
                            </FieldGroup>
                            <div className="flex items-end">
                                <Button type="submit" variant="secondary">
                                    Evaluate
                                </Button>
                            </div>
                        </form>
                        {previewResult ? (
                            <div className="rounded-md border border-border bg-card p-3 md:col-span-2">
                                <p className="text-sm font-medium">
                                    {previewResult.rule_name ?? 'No match'}
                                    {previewResult.rate_percent
                                        ? ` · ${previewResult.rate_percent}%`
                                        : ''}
                                </p>
                                {previewResult.explanation ? (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {previewResult.explanation}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                    </FormSection>
                ) : null}

                <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xs">
                    <ResponsiveDataTable
                        caption="TDS rules"
                        columns={columns}
                        rows={rules}
                        rowKey={(row) => row.id}
                        emptyTitle="No TDS rules yet"
                        emptyDescription="Create a rule using the form above."
                        actions={(rule) => (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="gap-1"
                                onClick={() =>
                                    setEditingRuleId(
                                        editingRuleId === rule.id
                                            ? null
                                            : rule.id,
                                    )
                                }
                            >
                                <Pencil className="size-3.5" />
                                {editingRuleId === rule.id ? 'Close' : 'Edit'}
                            </Button>
                        )}
                    />
                </div>

                {editingRule ? (
                    <RuleUpdatePanel
                        rule={editingRule}
                        fields={fields}
                        onClose={() => setEditingRuleId(null)}
                    />
                ) : null}
            </PageShell>
        </>
    );
}

function SimpleConditionsHeading({ className }: { className?: string }) {
    return (
        <p className={`text-sm font-medium ${className ?? ''}`}>
            Simple conditions
        </p>
    );
}

function FieldGroup({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            {children}
        </div>
    );
}

function RuleUpdatePanel({
    rule,
    fields,
    onClose,
}: {
    rule: RuleRow;
    fields: string[];
    onClose: () => void;
}) {
    const parsed = parseConditions(rule.conditions);
    const [isActive, setIsActive] = useState(rule.is_active);
    const [priority, setPriority] = useState(String(rule.priority));
    const [logic, setLogic] = useState(rule.condition_logic);
    const [ratePercent, setRatePercent] = useState(rule.rate_percent);
    const [rows, setRows] = useState<ConditionRowState[]>(() =>
        conditionsToRows(parsed),
    );
    const conditionsJson = JSON.stringify(rowsToConditions(rows));

    return (
        <Form action={rule.update_url} method="post" className="mt-4">
            <input type="hidden" name="_method" value="put" />
            <input
                type="hidden"
                name="conditions_json"
                value={conditionsJson}
            />
            <FormSection
                title={`Update · ${rule.name}`}
                description={rule.conditions_summary}
                columns={2}
            >
                <div className="space-y-2">
                    <Label htmlFor={`edit-active-${rule.id}`}>Status</Label>
                    <select
                        id={`edit-active-${rule.id}`}
                        name="is_active"
                        value={isActive ? '1' : '0'}
                        onChange={(event) =>
                            setIsActive(event.target.value === '1')
                        }
                        className={selectClass}
                    >
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`edit-priority-${rule.id}`}>Priority</Label>
                    <Input
                        id={`edit-priority-${rule.id}`}
                        name="priority"
                        type="number"
                        value={priority}
                        onChange={(event) => setPriority(event.target.value)}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`edit-rate-${rule.id}`}>TDS rate (%)</Label>
                    <Input
                        id={`edit-rate-${rule.id}`}
                        name="rate_percent"
                        type="number"
                        step="0.001"
                        value={ratePercent}
                        onChange={(event) => setRatePercent(event.target.value)}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`edit-logic-${rule.id}`}>
                        Condition logic
                    </Label>
                    <select
                        id={`edit-logic-${rule.id}`}
                        name="condition_logic"
                        value={logic}
                        onChange={(event) => setLogic(event.target.value)}
                        className={selectClass}
                    >
                        <option value="AND">AND</option>
                        <option value="OR">OR</option>
                    </select>
                </div>

                <div className="space-y-2 md:col-span-2">
                    <Label>Conditions</Label>
                    {rows.map((row) => (
                        <div
                            key={row.id}
                            className="mb-2 grid gap-2 md:grid-cols-12"
                        >
                            <div className="md:col-span-3">
                                <select
                                    value={row.field}
                                    onChange={(event) =>
                                        setRows((prev) =>
                                            prev.map((item) =>
                                                item.id === row.id
                                                    ? {
                                                          ...item,
                                                          field: event.target
                                                              .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className={selectClass}
                                >
                                    {fields.map((field) => (
                                        <option key={field} value={field}>
                                            {field}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="md:col-span-2">
                                <select
                                    value={row.operator}
                                    onChange={(event) =>
                                        setRows((prev) =>
                                            prev.map((item) =>
                                                item.id === row.id
                                                    ? {
                                                          ...item,
                                                          operator:
                                                              event.target
                                                                  .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                    className={selectClass}
                                >
                                    {OPERATORS.map((op) => (
                                        <option key={op} value={op}>
                                            {op}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="md:col-span-6">
                                <Input
                                    value={row.value}
                                    onChange={(event) =>
                                        setRows((prev) =>
                                            prev.map((item) =>
                                                item.id === row.id
                                                    ? {
                                                          ...item,
                                                          value: event.target
                                                              .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </div>
                            <div className="flex md:col-span-1 md:justify-end">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    disabled={rows.length <= 1}
                                    onClick={() =>
                                        setRows((prev) =>
                                            prev.filter(
                                                (item) => item.id !== row.id,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        </div>
                    ))}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            setRows((prev) => [
                                ...prev,
                                newConditionRow(fields[0] ?? 'pan_status'),
                            ])
                        }
                    >
                        Add condition
                    </Button>
                </div>

                <FieldGroup label="Human summary">
                    <p className="text-sm text-muted-foreground">
                        {parsed
                            .map(
                                (row) =>
                                    `${row.field} ${row.operator} ${formatConditionValue(row.value)}`,
                            )
                            .join(` ${logic} `) || '—'}
                    </p>
                </FieldGroup>

                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor={`remark-${rule.id}`} required>
                        Remark
                    </Label>
                    <Input
                        id={`remark-${rule.id}`}
                        name="remark"
                        required
                        placeholder="Required audit remark"
                    />
                </div>

                <div className="flex flex-wrap gap-2 md:col-span-2">
                    <AppIconAction
                        icon={Pencil}
                        label="Update TDS rule"
                        type="submit"
                        variant="warning"
                    >
                        <span className="text-sm font-medium">Update</span>
                    </AppIconAction>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                </div>
            </FormSection>
        </Form>
    );
}
