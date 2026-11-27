import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Calculator, Pencil, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PortalFlashBanner } from '@/components/admin/portal-feedback';
import {
    ResponsiveDataTable,
    type DataTableColumn,
} from '@/components/data-table';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import { FormSection } from '@/components/form-section';
import InputError from '@/components/input-error';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { formatInr } from '@/lib/money';

type ModuleOption = { value: string; label: string; capacity_label: string };

type PlanRow = {
    id: string;
    module: string;
    name: string;
    annual_price: string;
    capacity_units: number;
    is_active: boolean;
    update_url: string | null;
};

type AddonRow = {
    id: string;
    code: string;
    name: string;
    annual_price: string;
    unit_label: string | null;
    is_quantifiable: boolean;
    credit_units_per_unit: number | null;
    storage_gb_per_unit: number | null;
    is_active: boolean;
    update_url: string | null;
};

type Props = {
    canManage: boolean;
    catalogue: {
        code: string;
        name: string;
        effective_from: string | null;
    } | null;
    modules: ModuleOption[];
    plans: PlanRow[];
    addons: AddonRow[];
    pricingRules: {
        included_branches: number;
        seats_per_included_branch: number;
        included_storage_gb: number;
        bundle_discounts: { label: string; percent: string }[];
        tenures: { value: string; label: string; discount_percent: string }[];
    };
    gst: {
        gst_rate_id: string | null;
        gst_rate_percent: string | null;
        updated_at: string | null;
        options: { id: string; label: string }[];
        update_url: string | null;
    };
    links: { calculator: string; store_plan: string | null };
};

type EditTarget =
    | { kind: 'plan'; row: PlanRow; module: ModuleOption }
    | { kind: 'addon'; row: AddonRow }
    | { kind: 'new-plan'; module: ModuleOption };

export default function SubscriptionCatalogue({
    canManage,
    catalogue,
    modules,
    plans,
    addons,
    pricingRules,
    gst,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [editTarget, setEditTarget] = useState<EditTarget | null>(null);

    return (
        <>
            <Head title="Subscription Catalogue" />
            <PageShell
                title="Subscription Catalogue"
                description={
                    catalogue
                        ? `Active catalogue ${catalogue.name}. Price changes apply to future purchases and renewals; existing contracts and invoices keep their prices.`
                        : 'No active subscription catalogue is configured.'
                }
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={links.calculator}>
                            <Calculator className="size-4" aria-hidden />
                            Open Calculator
                        </Link>
                    </Button>
                }
            >
                <PortalFlashBanner message={flash?.success} />

                <SubscriptionGstSection gst={gst} canManage={canManage} />

                {modules.map((module) => (
                    <PlanTable
                        key={module.value}
                        module={module}
                        plans={plans.filter((p) => p.module === module.value)}
                        canCreate={links.store_plan !== null}
                        onEdit={(row) =>
                            setEditTarget({ kind: 'plan', row, module })
                        }
                        onCreate={() =>
                            setEditTarget({ kind: 'new-plan', module })
                        }
                    />
                ))}

                <AddonTable
                    addons={addons}
                    onEdit={(row) => setEditTarget({ kind: 'addon', row })}
                />

                <PricingRules rules={pricingRules} />
            </PageShell>

            {editTarget ? (
                <CatalogueItemDialog
                    key={
                        editTarget.kind === 'new-plan'
                            ? `new-${editTarget.module.value}`
                            : editTarget.row.id
                    }
                    target={editTarget}
                    storeUrl={links.store_plan}
                    onClose={() => setEditTarget(null)}
                />
            ) : null}
        </>
    );
}

function SubscriptionGstSection({
    gst,
    canManage,
}: {
    gst: Props['gst'];
    canManage: boolean;
}) {
    const form = useForm({ gst_rate_id: gst.gst_rate_id ?? '', remark: '' });
    const isConfigured = gst.gst_rate_id !== null;
    const changed = form.data.gst_rate_id !== (gst.gst_rate_id ?? '');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const rateLabel = (id: string | null) =>
        gst.options.find((option) => option.id === id)?.label ??
        'Not configured';
    const save = () => {
        setConfirmOpen(false);
        form.put(gst.update_url ?? '', {
            preserveScroll: true,
            onSuccess: () => form.setData('remark', ''),
        });
    };

    return (
        <FormSection
            title="Subscription GST"
            description="One GST rate from the GST Master applies to every subscription purchase, upgrade, add-on and renewal. The Billing Profile supplies the supplier GSTIN and state for the CGST/SGST or IGST split."
            columns={2}
        >
            <div className="space-y-1.5">
                <Label htmlFor="gst_rate_id" required>
                    GST Rate
                </Label>
                <select
                    id="gst_rate_id"
                    value={form.data.gst_rate_id}
                    disabled={!canManage || gst.update_url === null}
                    onChange={(e) =>
                        form.setData('gst_rate_id', e.target.value)
                    }
                    aria-invalid={form.errors.gst_rate_id ? true : undefined}
                    className="flex h-9 w-full rounded-md border border-input bg-background px-3 text-sm aria-invalid:border-destructive"
                >
                    <option value="">Select GST rate</option>
                    {gst.options.map((option) => (
                        <option key={option.id} value={option.id}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <InputError message={form.errors.gst_rate_id} />
                <p className="text-xs text-muted-foreground">
                    {isConfigured
                        ? `Current rate: ${Number(gst.gst_rate_percent)}%`
                        : 'Not configured — subscription quotes carry no GST until a rate is selected.'}
                </p>
            </div>
            {canManage && gst.update_url ? (
                <div className="space-y-1.5">
                    {isConfigured ? (
                        <>
                            <Label htmlFor="gst_remark" required>
                                Edit Remark
                            </Label>
                            <Input
                                id="gst_remark"
                                value={form.data.remark}
                                onChange={(e) =>
                                    form.setData('remark', e.target.value)
                                }
                                placeholder="Reason for changing the subscription GST rate"
                                aria-invalid={
                                    form.errors.remark ? true : undefined
                                }
                            />
                            <InputError message={form.errors.remark} />
                        </>
                    ) : null}
                    <Button
                        type="button"
                        className="mt-2"
                        disabled={form.processing || !changed}
                        onClick={() => setConfirmOpen(true)}
                    >
                        Save GST Rate
                    </Button>
                </div>
            ) : null}
            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="edit"
                title="Change subscription GST rate?"
                description="Applies to new quotes and checkouts. Paid and invoiced amounts keep their original rate."
                changes={[
                    {
                        label: 'GST Rate',
                        previous: rateLabel(gst.gst_rate_id),
                        next: rateLabel(form.data.gst_rate_id),
                        changeType: 'modified',
                    },
                    ...(isConfigured
                        ? [
                              {
                                  label: 'Remark',
                                  previous: '—',
                                  next: form.data.remark || '—',
                                  changeType: 'added' as const,
                              },
                          ]
                        : []),
                ]}
                processing={form.processing}
                onConfirm={save}
            />
        </FormSection>
    );
}

function PlanTable({
    module,
    plans,
    canCreate,
    onEdit,
    onCreate,
}: {
    module: ModuleOption;
    plans: PlanRow[];
    canCreate: boolean;
    onEdit: (row: PlanRow) => void;
    onCreate: () => void;
}) {
    const columns = useMemo<DataTableColumn<PlanRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Plan',
                priority: 0,
                cell: (row) => <span className="font-medium">{row.name}</span>,
            },
            {
                id: 'price',
                header: 'Annual Price',
                priority: 0,
                cell: (row) => formatInr(row.annual_price),
            },
            {
                id: 'capacity',
                header: `Included ${module.capacity_label}`,
                priority: 1,
                cell: (row) => row.capacity_units.toLocaleString('en-IN'),
            },
            {
                id: 'status',
                header: 'Availability',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'inactive'}
                    />
                ),
            },
        ],
        [module.capacity_label],
    );

    return (
        <section
            aria-labelledby={`module-${module.value}`}
            className="overflow-hidden rounded-xl border border-border bg-card shadow-xs"
        >
            <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                <h2 id={`module-${module.value}`} className="font-semibold">
                    {module.label} Plans
                </h2>
                {canCreate ? (
                    <Button size="sm" variant="outline" onClick={onCreate}>
                        <Plus className="size-4" aria-hidden />
                        Add Plan
                    </Button>
                ) : null}
            </div>
            <ResponsiveDataTable
                caption={`${module.label} plans`}
                columns={columns}
                rows={plans}
                rowKey={(row) => row.id}
                emptyTitle="No plans"
                emptyDescription="No plans are configured for this module."
                actions={(row) =>
                    row.update_url ? (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => onEdit(row)}
                            aria-label={`Edit ${row.name}`}
                        >
                            <Pencil className="size-4" aria-hidden />
                            Edit
                        </Button>
                    ) : null
                }
            />
        </section>
    );
}

function AddonTable({
    addons,
    onEdit,
}: {
    addons: AddonRow[];
    onEdit: (row: AddonRow) => void;
}) {
    const columns = useMemo<DataTableColumn<AddonRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Add-on',
                priority: 0,
                cell: (row) => <span className="font-medium">{row.name}</span>,
            },
            {
                id: 'price',
                header: 'Annual Price',
                priority: 0,
                cell: (row) =>
                    `${formatInr(row.annual_price)}${row.unit_label ? ` / ${row.unit_label}` : ''}`,
            },
            {
                id: 'pack',
                header: 'Pack Size',
                priority: 2,
                cell: (row) =>
                    row.credit_units_per_unit
                        ? `${row.credit_units_per_unit.toLocaleString('en-IN')} credits`
                        : row.storage_gb_per_unit
                          ? `${row.storage_gb_per_unit} GB`
                          : '—',
            },
            {
                id: 'status',
                header: 'Availability',
                priority: 1,
                cell: (row) => (
                    <WorkspaceStatusBadge
                        status={row.is_active ? 'active' : 'inactive'}
                    />
                ),
            },
        ],
        [],
    );

    return (
        <section
            aria-labelledby="addons-heading"
            className="overflow-hidden rounded-xl border border-border bg-card shadow-xs"
        >
            <div className="border-b border-border px-4 py-3">
                <h2 id="addons-heading" className="font-semibold">
                    Add-ons
                </h2>
            </div>
            <ResponsiveDataTable
                caption="Subscription add-ons"
                columns={columns}
                rows={addons}
                rowKey={(row) => row.id}
                emptyTitle="No add-ons"
                emptyDescription="No add-ons are configured."
                actions={(row) =>
                    row.update_url ? (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => onEdit(row)}
                            aria-label={`Edit ${row.name}`}
                        >
                            <Pencil className="size-4" aria-hidden />
                            Edit
                        </Button>
                    ) : null
                }
            />
        </section>
    );
}

function PricingRules({ rules }: { rules: Props['pricingRules'] }) {
    return (
        <FormSection
            title="Pricing Rules"
            description="Applied automatically by the pricing engine for every purchase and in the calculator."
            columns={3}
        >
            <div className="text-sm">
                <p className="font-medium">Included capacity</p>
                <p className="text-muted-foreground">
                    {rules.included_branches} branches,{' '}
                    {rules.included_branches * rules.seats_per_included_branch}{' '}
                    users, {rules.included_storage_gb} GB storage
                </p>
            </div>
            <div className="text-sm">
                <p className="font-medium">Multi-module discount</p>
                <ul className="text-muted-foreground">
                    {rules.bundle_discounts.map((d) => (
                        <li key={d.label}>
                            {d.label}: {d.percent}%
                        </li>
                    ))}
                </ul>
            </div>
            <div className="text-sm">
                <p className="font-medium">Billing periods</p>
                <ul className="text-muted-foreground">
                    {rules.tenures.map((t) => (
                        <li key={t.value}>
                            {t.label}: {t.discount_percent}% off
                        </li>
                    ))}
                </ul>
            </div>
        </FormSection>
    );
}

type ItemFormData = {
    module: string;
    name: string;
    annual_price: string;
    capacity_units: string;
    credit_units_per_unit: string;
    storage_gb_per_unit: string;
    is_active: boolean;
    remark: string;
};

function CatalogueItemDialog({
    target,
    storeUrl,
    onClose,
}: {
    target: EditTarget;
    storeUrl: string | null;
    onClose: () => void;
}) {
    const isCreate = target.kind === 'new-plan';
    const initial: ItemFormData = {
        module: target.kind === 'addon' ? '' : target.module.value,
        name: isCreate ? '' : target.row.name,
        annual_price: isCreate ? '' : target.row.annual_price,
        capacity_units:
            target.kind === 'plan' ? String(target.row.capacity_units) : '0',
        credit_units_per_unit:
            target.kind === 'addon' && target.row.credit_units_per_unit
                ? String(target.row.credit_units_per_unit)
                : '',
        storage_gb_per_unit:
            target.kind === 'addon' && target.row.storage_gb_per_unit
                ? String(target.row.storage_gb_per_unit)
                : '',
        is_active: isCreate ? true : target.row.is_active,
        remark: '',
    };
    const form = useForm<ItemFormData>(initial);
    const catalogueError = usePage<{ errors?: Record<string, string> }>().props
        .errors?.catalogue;
    const [step, setStep] = useState<'edit' | 'review'>('edit');

    const changes = useMemo(() => {
        const labels: [keyof ItemFormData, string][] = [
            ['name', 'Name'],
            ['annual_price', 'Annual Price'],
            ['capacity_units', 'Included Capacity'],
            ['credit_units_per_unit', 'Credits per Pack'],
            ['storage_gb_per_unit', 'Storage per Pack (GB)'],
            ['is_active', 'Availability'],
        ];

        return labels
            .filter(([key]) => String(initial[key]) !== String(form.data[key]))
            .map(([key, label]) => ({
                label,
                previous: displayValue(key, initial[key]),
                next: displayValue(key, form.data[key]),
            }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data]);

    const title = isCreate
        ? `Add ${target.module.label} Plan`
        : `Edit ${target.row.name}`;
    const submitUrl = isCreate ? storeUrl : target.row.update_url;

    const submit = () => {
        if (!submitUrl) {
            return;
        }
        const options = {
            preserveScroll: true,
            onSuccess: onClose,
            onError: () => setStep('edit'),
        };
        if (isCreate) {
            form.post(submitUrl, options);
        } else {
            form.put(submitUrl, options);
        }
    };

    const showCapacity = target.kind !== 'addon';
    const capacityLabel =
        target.kind === 'addon'
            ? ''
            : `Included ${target.module.capacity_label}`;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {step === 'review' ? 'Confirm changes' : title}
                    </DialogTitle>
                    <DialogDescription>
                        {step === 'review'
                            ? 'Existing contracts, payments and invoices keep their original prices.'
                            : isCreate
                              ? 'The new plan becomes available for new purchases.'
                              : 'Changes apply to future purchases and renewals only.'}
                    </DialogDescription>
                </DialogHeader>

                {catalogueError ? (
                    <p role="alert" className="text-sm text-destructive">
                        {catalogueError}
                    </p>
                ) : null}

                {step === 'edit' ? (
                    <form
                        id="catalogue-item-form"
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (isCreate) {
                                submit();
                            } else {
                                setStep('review');
                            }
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="item_name" required>
                                Name
                            </Label>
                            <Input
                                id="item_name"
                                name="name"
                                required
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                aria-invalid={
                                    form.errors.name ? true : undefined
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="item_annual_price" required>
                                    Annual Price (₹)
                                </Label>
                                <Input
                                    id="item_annual_price"
                                    name="annual_price"
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    required
                                    value={form.data.annual_price}
                                    onChange={(e) =>
                                        form.setData(
                                            'annual_price',
                                            e.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        form.errors.annual_price
                                            ? true
                                            : undefined
                                    }
                                />
                                <InputError
                                    message={form.errors.annual_price}
                                />
                            </div>
                            {showCapacity ? (
                                <div className="space-y-1.5">
                                    <Label htmlFor="item_capacity" required>
                                        {capacityLabel}
                                    </Label>
                                    <Input
                                        id="item_capacity"
                                        name="capacity_units"
                                        type="number"
                                        min="0"
                                        required
                                        value={form.data.capacity_units}
                                        onChange={(e) =>
                                            form.setData(
                                                'capacity_units',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.capacity_units}
                                    />
                                </div>
                            ) : null}
                            {target.kind === 'addon' &&
                            target.row.credit_units_per_unit ? (
                                <div className="space-y-1.5">
                                    <Label htmlFor="item_credits" required>
                                        Credits per Pack
                                    </Label>
                                    <Input
                                        id="item_credits"
                                        name="credit_units_per_unit"
                                        type="number"
                                        min="1"
                                        required
                                        value={form.data.credit_units_per_unit}
                                        onChange={(e) =>
                                            form.setData(
                                                'credit_units_per_unit',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            form.errors.credit_units_per_unit
                                        }
                                    />
                                </div>
                            ) : null}
                            {target.kind === 'addon' &&
                            target.row.storage_gb_per_unit ? (
                                <div className="space-y-1.5">
                                    <Label htmlFor="item_storage" required>
                                        Storage per Pack (GB)
                                    </Label>
                                    <Input
                                        id="item_storage"
                                        name="storage_gb_per_unit"
                                        type="number"
                                        min="1"
                                        required
                                        value={form.data.storage_gb_per_unit}
                                        onChange={(e) =>
                                            form.setData(
                                                'storage_gb_per_unit',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            form.errors.storage_gb_per_unit
                                        }
                                    />
                                </div>
                            ) : null}
                        </div>
                        {!isCreate ? (
                            <>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="is_active"
                                        checked={form.data.is_active}
                                        onChange={(e) =>
                                            form.setData(
                                                'is_active',
                                                e.target.checked,
                                            )
                                        }
                                        className="size-4 rounded border border-input"
                                    />
                                    Available for new purchases
                                </label>
                                <div className="space-y-1.5">
                                    <Label htmlFor="item_remark" required>
                                        Edit Remark
                                    </Label>
                                    <Input
                                        id="item_remark"
                                        name="remark"
                                        required
                                        value={form.data.remark}
                                        onChange={(e) =>
                                            form.setData(
                                                'remark',
                                                e.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            form.errors.remark
                                                ? true
                                                : undefined
                                        }
                                    />
                                    <InputError message={form.errors.remark} />
                                </div>
                            </>
                        ) : null}
                    </form>
                ) : (
                    <div className="overflow-x-auto rounded-md border border-border">
                        <table className="min-w-full text-sm">
                            <thead className="bg-muted/40 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Field
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Previous
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        New
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {changes.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={3}
                                            className="px-3 py-3 text-muted-foreground"
                                        >
                                            No field changes detected.
                                        </td>
                                    </tr>
                                ) : (
                                    changes.map((change) => (
                                        <tr
                                            key={change.label}
                                            className="border-t border-border"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {change.label}
                                            </td>
                                            <td className="px-3 py-2 text-muted-foreground">
                                                {change.previous}
                                            </td>
                                            <td className="px-3 py-2">
                                                {change.next}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                        <p className="border-t border-border px-3 py-2 text-xs text-muted-foreground">
                            Remark: {form.data.remark}
                        </p>
                    </div>
                )}

                <DialogFooter>
                    {step === 'review' ? (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep('edit')}
                            >
                                Back to edit
                            </Button>
                            <Button
                                type="button"
                                disabled={
                                    form.processing || changes.length === 0
                                }
                                onClick={submit}
                            >
                                Confirm save
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                form="catalogue-item-form"
                                disabled={form.processing}
                            >
                                {isCreate ? 'Create Plan' : 'Review changes'}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function displayValue(
    key: keyof ItemFormData,
    value: string | boolean,
): string {
    if (key === 'is_active') {
        return value ? 'Available' : 'Unavailable';
    }
    if (key === 'annual_price') {
        return formatInr(String(value));
    }

    return String(value) || '—';
}
