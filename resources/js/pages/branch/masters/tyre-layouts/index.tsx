import { Head, router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AppIconAction } from '@/components/app-icon-action';
import {
    AppIndexFilters,
    AppIndexListCard,
    AppIndexPage,
} from '@/components/app-index-page';
import { FormConfirmationDialog } from '@/components/form-confirmation/form-confirmation-dialog';
import type { FieldReview } from '@/components/form-confirmation/types';
import {
    TyreLayoutFormFields,
    type TyreAxleDraft,
} from '@/components/masters/tyre-layout-form-fields';
import { TyreLayoutPreview } from '@/components/masters/tyre-layout-preview';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getCsrfToken } from '@/lib/csrf';

type LayoutItem = {
    id: string;
    name: string;
    spare_tyre_count: number;
    drive_tyre_count: number;
    total_tyre_count: number;
    needs_axle_completion: boolean;
    is_active: boolean;
    sort_order: number;
    axles: Array<{
        sequence: number;
        configuration: 'single' | 'dual';
        label: string;
    }>;
    positions: Array<{
        id: string;
        position_key: string;
        label: string;
        axle_sequence: number;
        side: string;
        ring: string | null;
    }>;
};

type Props = {
    branch: { id: string; name: string; status: string };
    canManageOwnerCatalogues: boolean;
    filters: { q: string };
    items: { data: LayoutItem[]; meta: { total: number } };
    links: { hub: string; index: string; store: string };
};

const defaultAxles = (): TyreAxleDraft[] => [
    { sequence: 1, configuration: 'single' },
    { sequence: 2, configuration: 'dual' },
    { sequence: 3, configuration: 'dual' },
];

export default function TyreLayoutsIndex({
    branch,
    canManageOwnerCatalogues,
    filters,
    items,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [name, setName] = useState('');
    const [spareTyreCount, setSpareTyreCount] = useState(1);
    const [axles, setAxles] = useState<TyreAxleDraft[]>(defaultAxles);
    const [errors, setErrors] = useState<Record<string, string[] | string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    const reviewFields: FieldReview[] = [
        { label: 'Layout name', value: name },
        { label: 'Axles', value: String(axles.length) },
        { label: 'Spare tyres', value: String(spareTyreCount) },
    ];

    const submitCreate = async () => {
        setProcessing(true);
        setFormError(null);
        setErrors({});

        try {
            const response = await fetch(links.store, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: name.trim(),
                    spare_tyre_count: spareTyreCount,
                    axles,
                }),
            });

            if (!response.ok) {
                const json = (await response.json().catch(() => null)) as {
                    message?: string;
                    errors?: Record<string, string[] | string>;
                } | null;
                if (json?.errors) {
                    setErrors(json.errors);
                }
                setFormError(json?.message ?? 'Could not create tyre layout.');
                setConfirmOpen(false);
                setProcessing(false);
                return;
            }

            setConfirmOpen(false);
            setProcessing(false);
            setName('');
            setSpareTyreCount(1);
            setAxles(defaultAxles());
            router.reload();
        } catch {
            setFormError('Network error while creating tyre layout.');
            setConfirmOpen(false);
            setProcessing(false);
        }
    };

    return (
        <>
            <Head title={`Tyre layouts · ${branch.name}`} />
            <AppIndexPage>
                <AppIndexListCard title="Tyre layouts">
                    {flash?.success ? (
                        <p
                            role="status"
                            className="border-b border-[#e5e7eb] bg-[#f8fafc] px-4 py-2 text-sm text-[#334155]"
                        >
                            {flash.success}
                        </p>
                    ) : null}

                    <p className="border-b border-[#e5e7eb] px-4 py-2 text-sm text-slate-500">
                        Structured axle configuration for own-fleet vehicles.
                        Position identifiers are derived automatically. Historic
                        layouts may need axle completion.
                    </p>

                    <AppIndexFilters
                        onSubmit={(event) => {
                            event.preventDefault();
                            const form = event.currentTarget;
                            const q = (
                                form.elements.namedItem('q') as HTMLInputElement
                            ).value.trim();
                            router.get(
                                links.index,
                                { q: q || undefined },
                                { preserveState: true, replace: true },
                            );
                        }}
                    >
                        <div className="space-y-1">
                            <Label htmlFor="q">Search</Label>
                            <Input
                                id="q"
                                name="q"
                                defaultValue={filters.q}
                                className="min-w-[12rem]"
                            />
                        </div>
                        <Button
                            type="submit"
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            Filter
                        </Button>
                    </AppIndexFilters>

                    {canManageOwnerCatalogues ? (
                        <form
                            className="space-y-4 border-b border-[#e5e7eb] bg-white px-4 py-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                setFormError(null);
                                if (name.trim() === '' || axles.length === 0) {
                                    setFormError(
                                        'Layout name and at least one axle are required.',
                                    );
                                    return;
                                }
                                setConfirmOpen(true);
                            }}
                        >
                            <h3 className="text-sm font-semibold text-[#334155]">
                                Add tyre layout
                            </h3>
                            {formError ? (
                                <p
                                    className="text-sm text-destructive"
                                    role="alert"
                                >
                                    {formError}
                                </p>
                            ) : null}
                            <TyreLayoutFormFields
                                layoutName={name}
                                spareTyreCount={spareTyreCount}
                                axles={axles}
                                errors={errors}
                                onLayoutNameChange={setName}
                                onSpareTyreCountChange={setSpareTyreCount}
                                onAxlesChange={setAxles}
                            />
                            <Button
                                type="submit"
                                className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                            >
                                Review & create
                            </Button>
                        </form>
                    ) : (
                        <p className="border-b border-[#e5e7eb] px-4 py-2 text-sm text-slate-500">
                            Owner-only create and deactivate for tyre layouts.
                        </p>
                    )}

                    <div className="space-y-4 px-4 py-4">
                        {items.data.length === 0 ? (
                            <p className="app-index-empty">
                                No tyre layouts yet. Add a structured layout
                                above.
                            </p>
                        ) : (
                            items.data.map((item) => (
                                <article
                                    key={item.id}
                                    className="rounded-xl border border-[#e5e7eb] bg-white p-4"
                                >
                                    <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h4 className="font-medium text-slate-800">
                                                {item.name}
                                            </h4>
                                            <p className="text-sm text-slate-500">
                                                {item.drive_tyre_count} running
                                                · {item.spare_tyre_count} spare
                                                · {item.total_tyre_count} total
                                                {item.needs_axle_completion
                                                    ? ' · needs axle completion'
                                                    : ''}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <WorkspaceStatusBadge
                                                status={
                                                    item.is_active
                                                        ? 'Active'
                                                        : 'Inactive'
                                                }
                                            />
                                            {canManageOwnerCatalogues ? (
                                                <form
                                                    method="post"
                                                    action={`/tyre-layouts/${item.id}`}
                                                    onSubmit={(event) => {
                                                        event.preventDefault();
                                                        router.delete(
                                                            `/tyre-layouts/${item.id}`,
                                                            {
                                                                data: {
                                                                    remark: 'Delete tyre layout',
                                                                },
                                                            },
                                                        );
                                                    }}
                                                >
                                                    <AppIconAction
                                                        icon={Trash2}
                                                        label="Deactivate tyre layout"
                                                        type="submit"
                                                        variant="destructive"
                                                    />
                                                </form>
                                            ) : null}
                                        </div>
                                    </div>
                                    <TyreLayoutPreview
                                        axles={item.axles}
                                        positions={item.positions}
                                        spareTyreCount={item.spare_tyre_count}
                                        compact
                                    />
                                </article>
                            ))
                        )}
                    </div>
                </AppIndexListCard>
            </AppIndexPage>

            <FormConfirmationDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                mode="create"
                title="Create tyre layout?"
                description="Confirm layout name and axle configuration."
                fields={reviewFields}
                processing={processing}
                onConfirm={() => {
                    void submitCreate();
                }}
                confirmLabel={processing ? 'Saving…' : 'Create'}
            />
        </>
    );
}
