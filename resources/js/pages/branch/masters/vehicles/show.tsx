import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    permissions: { can_edit: boolean; can_delete: boolean };
    vehicle: Record<string, unknown> & {
        id: string;
        registration_number: string;
    };
    documentDrawer: Omit<
        DocumentDrawerProps,
        'open' | 'onOpenChange' | 'title'
    > | null;
    links: {
        hub: string;
        dashboard: string;
        index: string;
        edit: string;
        destroy: string;
        documents: string;
    };
};

export default function VehicleShow({
    permissions,
    vehicle,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);

    return (
        <>
            <Head title={String(vehicle.registration_number)} />
            <PageShell title={String(vehicle.registration_number)}>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {permissions.can_edit ? (
                        <Button asChild variant="outline">
                            <Link href={links.edit}>Edit</Link>
                        </Button>
                    ) : null}
                    {documentDrawer ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDocsOpen(true)}
                        >
                            Documents
                        </Button>
                    ) : (
                        <Button asChild variant="outline">
                            <Link href={links.documents}>Documents</Link>
                        </Button>
                    )}
                    {permissions.can_delete ? (
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                const remark = window.prompt(
                                    'Remark for soft deletion',
                                );
                                if (!remark) return;
                                router.delete(links.destroy, {
                                    data: { remark },
                                });
                            }}
                        >
                            Soft delete
                        </Button>
                    ) : null}
                </div>
                <dl className="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2">
                    {Object.entries(vehicle)
                        .filter(
                            ([k]) =>
                                k !== 'id' &&
                                k !== 'scope' &&
                                k !== 'branch_id',
                        )
                        .map(([key, value]) => (
                            <div key={key}>
                                <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                    {key.replaceAll('_', ' ')}
                                </dt>
                                <dd className="mt-1 text-sm">
                                    {value == null || value === ''
                                        ? '—'
                                        : typeof value === 'string' ||
                                            typeof value === 'number' ||
                                            typeof value === 'boolean'
                                          ? String(value)
                                          : JSON.stringify(value)}
                                </dd>
                            </div>
                        ))}
                </dl>
            </PageShell>
            {documentDrawer ? (
                <DocumentDrawer
                    open={docsOpen}
                    onOpenChange={setDocsOpen}
                    {...documentDrawer}
                />
            ) : null}
        </>
    );
}
