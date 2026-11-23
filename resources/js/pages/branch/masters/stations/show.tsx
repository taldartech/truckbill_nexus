import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import {
    DocumentDrawer,
    type DocumentDrawerProps,
} from '@/components/masters/document-drawer';
import { PageShell } from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    company: { id: string; name: string; status: string };
    branch: { id: string; name: string; status: string };
    canManageOwnerStations: boolean;
    permissions: { can_edit: boolean; can_delete: boolean };
    station: {
        id: string;
        code: string;
        name: string;
        state: string | null;
        city: string | null;
        is_active: boolean;
        default_transporter_party_id: string | null;
    };
    documentDrawer: Omit<
        DocumentDrawerProps,
        'open' | 'onOpenChange' | 'title'
    > | null;
    links: {
        hub: string;
        index: string;
        edit: string;
        destroy: string;
        set_default_transporter: string;
        documents: string;
    };
};
export default function StationShow({
    canManageOwnerStations,
    permissions,
    station,
    documentDrawer,
    links,
}: Props) {
    const { flash } = usePage<{ flash?: { success?: string | null } }>().props;
    const [docsOpen, setDocsOpen] = useState(false);
    return (
        <>
            <Head title={station.name} />
            <PageShell title={station.name}>
                {flash?.success ? (
                    <p
                        role="status"
                        className="rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm"
                    >
                        {flash.success}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {canManageOwnerStations ? (
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
                    {canManageOwnerStations && permissions.can_delete ? (
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
                    {[
                        ['Code', station.code],
                        ['State', station.state],
                        ['City', station.city],
                        ['Status', station.is_active ? 'Active' : 'Inactive'],
                        [
                            'Default transporter',
                            station.default_transporter_party_id,
                        ],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                                {label}
                            </dt>
                            <dd className="mt-1 text-sm">{value || '—'}</dd>
                        </div>
                    ))}
                </dl>
                {canManageOwnerStations ? (
                    <Form
                        action={links.set_default_transporter}
                        method="post"
                        className="grid max-w-xl gap-3 rounded-xl border border-border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <h2 className="font-medium">
                                    Default transporter
                                </h2>
                                <div className="space-y-2">
                                    <Label htmlFor="transporter_party_id">
                                        Transporter party ID
                                    </Label>
                                    <Input
                                        id="transporter_party_id"
                                        name="transporter_party_id"
                                        defaultValue={
                                            station.default_transporter_party_id ??
                                            ''
                                        }
                                    />
                                    <InputError
                                        message={errors.transporter_party_id}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="scope">Scope</Label>
                                    <select
                                        id="scope"
                                        name="scope"
                                        defaultValue="branch"
                                        className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="branch">
                                            This branch
                                        </option>
                                        <option value="company">
                                            Company default
                                        </option>
                                    </select>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="remark">Remark</Label>
                                    <Input
                                        id="remark"
                                        name="remark"
                                        required
                                        defaultValue="Set default transporter"
                                    />
                                    <InputError message={errors.remark} />
                                </div>
                                <Button type="submit" disabled={processing}>
                                    Save default transporter
                                </Button>
                            </>
                        )}
                    </Form>
                ) : null}
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
